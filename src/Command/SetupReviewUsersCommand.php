<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\SecurityBundle\Entity\Permission;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Component\Security\Authorization\MaskConverterInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the four accounts the review workflow needs to be exercised end to end.
 *
 * A review flow cannot be tested with one login: the request creator is barred from approving their own
 * request, and `required_approvals: 2` needs two *different* reviewers. The fourth account exists to
 * prove the negative, an editor without the `review` permission gets a 403 from the approve endpoint.
 *
 * Idempotent: re-running updates the existing accounts instead of failing on the unique username.
 */
#[AsCommand(
    name: 'app:setup-review-users',
    description: 'Creates author, reviewer and publisher accounts for testing the review workflow.',
)]
final class SetupReviewUsersCommand extends Command
{
    private const PASSWORD = 'test';

    /**
     * The security contexts the review flow touches. Pages and articles are what the default workflow
     * covers; the two list contexts are needed or the admin cannot even open the lists.
     */
    private const CONTEXTS = [
        // The webspace context is what unlocks the page tree; its key comes from
        // config/webspaces/*.xml. The rest are what the review flow touches or needs to navigate.
        'sulu.webspaces.website',
        'sulu.article.articles',
        'sulu.global.snippets',
        'sulu.snippet.snippets',
        'sulu.media.collections',
        'sulu.settings.tags',
        'sulu.settings.categories',
        'sulu.contact.people',
    ];

    /**
     * @var array<string, array{permissions: list<string>, description: string}>
     */
    private const ACCOUNTS = [
        'wf_author' => [
            'permissions' => [PermissionTypes::VIEW, PermissionTypes::ADD, PermissionTypes::EDIT],
            'description' => 'writes and sends for review, cannot approve and cannot publish',
        ],
        'wf_reviewer_one' => [
            'permissions' => [PermissionTypes::VIEW, PermissionTypes::EDIT, PermissionTypes::REVIEW],
            'description' => 'can approve or reject, cannot publish directly',
        ],
        'wf_reviewer_two' => [
            'permissions' => [PermissionTypes::VIEW, PermissionTypes::EDIT, PermissionTypes::REVIEW],
            'description' => 'the second approval required by the default workflow',
        ],
        'wf_publisher' => [
            'permissions' => [
                PermissionTypes::VIEW,
                PermissionTypes::ADD,
                PermissionTypes::EDIT,
                PermissionTypes::REVIEW,
                PermissionTypes::LIVE,
            ],
            'description' => 'holds `live`, so "Bypass review and publish" is offered',
        ],
        'wf_editor_no_review' => [
            'permissions' => [PermissionTypes::VIEW, PermissionTypes::EDIT],
            'description' => 'proves the negative: approving returns 403',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MaskConverterInterface $maskConverter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $rows = [];

        // Two passes with a flush in between. The permission table is unique on (context, role), and
        // Doctrine orders inserts before deletes inside one flush, so clearing and re-adding the same
        // context in a single unit of work collides with itself.
        $roles = [];
        foreach (self::ACCOUNTS as $username => $account) {
            $roles[$username] = $this->createOrUpdateUser($username);
            $rows[] = [$username, self::PASSWORD, \implode(', ', $account['permissions']), $account['description']];
        }
        $this->entityManager->flush();

        foreach (self::ACCOUNTS as $username => $account) {
            $this->grantPermissions($roles[$username], $account['permissions']);
        }
        $this->entityManager->flush();

        $symfonyStyle->success('Review workflow accounts are ready.');
        $symfonyStyle->table(['User', 'Password', 'Permissions', 'Role in the flow'], $rows);
        $symfonyStyle->note(
            'The default workflow requires 2 approvals and forbids self-review, so send for review as'
                . ' wf_author and then approve as wf_reviewer_one and wf_reviewer_two.',
        );

        return self::SUCCESS;
    }

    private function createOrUpdateUser(string $username): Role
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);

        if (!$user instanceof User) {
            $contact = new Contact();
            $contact->setFirstName(\ucfirst(\str_replace('wf_', '', $username)));
            $contact->setLastName('Workflow');
            $this->entityManager->persist($contact);

            $user = new User();
            $user->setUsername($username);
            $user->setEmail($username . '@example.com');
            $user->setSalt('');
            $user->setContact($contact);
            $this->entityManager->persist($user);
        }

        $user->setLocale('en');
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));

        return $this->attachRole($user, $username);
    }

    private function attachRole(User $user, string $username): Role
    {
        $roleName = 'workflow_' . $username;

        $role = $this->entityManager->getRepository(Role::class)->findOneBy(['name' => $roleName]);
        if (!$role instanceof Role) {
            $role = new Role();
            $role->setName($roleName);
            $role->setSystem('Sulu');
            $this->entityManager->persist($role);
        }

        // Cleared here and refilled in the second pass, so editing the context or permission lists
        // above actually takes effect instead of merging into what the previous run left behind.
        foreach ($role->getPermissions() as $existingPermission) {
            $role->removePermission($existingPermission);
            $this->entityManager->remove($existingPermission);
        }

        foreach ($user->getUserRoles() as $existingUserRole) {
            if ($existingUserRole->getRole() === $role) {
                $existingUserRole->setLocale('["en","de"]');

                return $role;
            }
        }

        $userRole = new UserRole();
        $userRole->setUser($user);
        $userRole->setRole($role);
        $userRole->setLocale('["en","de"]');
        $this->entityManager->persist($userRole);
        $user->addUserRole($userRole);

        return $role;
    }

    /**
     * @param list<string> $permissionTypes
     */
    private function grantPermissions(Role $role, array $permissionTypes): void
    {
        $mask = $this->maskConverter->convertPermissionsToNumber(
            \array_fill_keys($permissionTypes, true),
        );

        foreach (self::CONTEXTS as $context) {
            $permission = new Permission();
            $permission->setRole($role);
            $permission->setContext($context);
            $permission->setPermissions($mask);
            $this->entityManager->persist($permission);
            $role->addPermission($permission);
        }
    }
}
