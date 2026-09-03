# Test "Request for publish"

This branch of the skeleton is wired for the `feature/workflow-transition-request` branch of `Prokyonn/sulu`
(eleven commits on top of Sulu 3.1): admin route import, workflow config, the `review` accounts command, a demo
validator, a "Related pages" selection on the default page template, and a prebuilt admin bundle.

## 1. Install

```bash
git clone --branch feature/workflow-transition-request git@github.com:Prokyonn/skeleton.git wtr-test && cd wtr-test
composer install
```

Set `DATABASE_URL` in `.env.local`, then:

```bash
bin/adminconsole sulu:build dev
bin/adminconsole doctrine:migrations:migrate --no-interaction
bin/adminconsole app:setup-review-users
symfony server:start -d
```

Accounts (password `test`): `wf_author` (view, add, edit), `wf_reviewer_one` and `wf_reviewer_two` (review),
`wf_publisher` (review, live), `wf_editor_no_review` (view, edit). Configured: two approvals plus the
`unpublished_references` validator, so a request needs 3 approvals; SEO title and description and the excerpt title
are required before a request.

## 2. Test

1. `wf_author`: Pages > add page, save as draft. Save dropdown > "Save and request for publish" with empty SEO and excerpt. Expected: red error naming SEO title, SEO description and excerpt title; form still editable.
2. `wf_author`: fill SEO title, SEO description, excerpt title; in "Related pages" select a page that is only a draft. "Save and request for publish". Expected: form locked, yellow banner with "Cancel request for publish", yellow header dot. Hover the dot: dark tooltip "Requested for publish". Pages list: yellow dot on the page, grey dots on drafts.
3. `wf_reviewer_one`: open the page. Expected: banner without cancel. Approval (save icon) > Review: "0 of 3 approved", the validator row rejected with the unpublished page named, two "Approval is waiting" rows. Approve (comment optional). Expected: overlay stays open, "1 of 3 approved", Approve disabled as "You approved". Hover the dot: tooltip lists the approval.
4. `wf_reviewer_one`: Reject. Expected: Send disabled until a comment is typed. Send. Expected: "0 of 3 approved, 1 rejected", comment expandable. Approve again. Expected: "1 of 3 approved".
5. `wf_editor_no_review`: open the page. Expected: no Approval dropdown.
6. `wf_publisher`: publish the related page that was only a draft. `wf_reviewer_two`: open the request, click Retry on the validator row. Expected: validator row approved, "2 of 3 approved". Approve. Expected: "3 of 3 approved", banner "Ready to Publish".
7. `wf_publisher`: Approval > Publish enabled, Bypass disabled. Publish. Expected: green dot, page live, request closed.
8. `wf_author`: change the title of the published page. Expected: "Save and request for publish" enabled. Click it. Expected: draft saved and request opened in one step. Banner > "Cancel request for publish". Expected: form unlocked.
9. `wf_author`: new page with SEO and excerpt, request. `wf_publisher`: Approval > "Bypass review and publish", confirm. Expected: published without approvals.
10. While a request is open: copy locale into the locked locale or restore a version. Expected: refused, "content is in review".

## 3. Optional: validator on a worker

Uncomment the routing line in `config/packages/messenger.yaml`, then:

```bash
bin/adminconsole messenger:setup-transports
bin/adminconsole messenger:consume async -vv
```

Without a worker the validator row stays "waiting"; Retry re-queues it.

## 4. Known gaps

- Request action appears after the first save only.
- `resources` works on the `default` workflow only.
- Insights "Requests for publishing" list not built.
