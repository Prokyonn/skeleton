# Test "Request for publish"

This branch of the skeleton is wired for the `feature/workflow-transition-request` branch of `Prokyonn/sulu`:
admin route import, workflow config, the `review` accounts command, a demo validator, a "Related pages"
selection on the default page template, and a prebuilt admin bundle.

**The model in one paragraph.** People approve, checks report. The gate is
`required_human_approvals`; an automated check never counts towards it. A check marked
`blocking: true` holds the request until it passes. `live` publishes on its own authority and never
has to ask; `edit` publishes only what reviewers signed off, and only from inside the review overlay.

## 1. Install

```bash
git clone --branch feature/workflow-transition-request git@github.com:Prokyonn/skeleton.git wtr-test && cd wtr-test
composer install
```

`composer.lock` is committed on this branch, so everyone installs the exact `sulu/sulu` commit this
skeleton was built and tested against. After new sulu commits land, refresh it with
`composer update sulu/sulu` and commit the result.

Set `DATABASE_URL` in `.env.local`, then:

```bash
bin/adminconsole sulu:build dev
bin/adminconsole doctrine:migrations:migrate --no-interaction
bin/adminconsole app:setup-review-users
symfony server:start -d
```

> Tested an earlier state of this branch? The tables were renamed. Drop `wt_workflow_transition_requests`
> and `wt_workflow_transition_request_reviewers` before migrating: the migration guards on `hasTable()`
> and will not convert them.

Accounts (password `test`): `wf_author` (view, add, edit), `wf_reviewer_one` and `wf_reviewer_two`
(view, edit, review), `wf_publisher` (+ live), `wf_editor_no_review` (view, edit).

The `default` workflow is configured with `required_human_approvals: 2` and one blocking check,
`unpublished_references`. SEO title and description and the excerpt title are pre-validated.

## 2. The main path

1. `wf_author`: Pages > add page, save as draft. Save dropdown > "Save and request for publish" with empty SEO and excerpt. Expected: an overlay "Content is not ready to go live" listing every check as its own row, SEO fields red and Excerpt fields green (or both red) with "1 of 2 passed" in the header. Nothing was written: no request exists and the form is still editable.
2. `wf_author`: fill SEO title, SEO description, excerpt title; in "Related pages" select a page that is only a draft. "Save and request for publish". Expected: form locked, yellow banner with "Cancel request for publish", yellow header dot.
3. `wf_author`: the toolbar now shows a single **Review** button, not a dropdown. Open it. Expected: two cards — "Automated checks" with `unpublished_references` and "0 of 2 approved" below it; the check row is red and reads "Must pass before publishing" with the unpublished page named in a speech bubble. No Approve or Reject: you cannot review your own request. A **Retry** link is offered, because clearing a failed check is the author's job.
4. `wf_publisher`: publish the related page that was only a draft. `wf_author`: Review > Retry. Expected: the check turns green and reads "Passed", header "1 of 1 passed".
5. `wf_reviewer_one`: open the page > Review. Expected: Reject and Approve enabled. Approve with a comment. Expected: overlay stays open, "1 of 2 approved", Approve now disabled as "You approved", the comment shown as a speech bubble clamped to three lines.
6. `wf_reviewer_one`: Reject. Expected: Send disabled until a comment is typed. Send. Expected: "0 of 2 approved, 1 rejected". Approve again. Expected: "1 of 2 approved".
7. `wf_reviewer_two`: Approve. Expected: "2 of 2 approved" and the banner turns "Ready to Publish".
8. `wf_author`: Review. Expected: **Publish** in the overlay footer, and still no Approve or Reject. Click it. Expected: page live, request closed, banner gone. This is the point of the whole flow: the approval delegated the publish right to the author, who holds no `live`.
9. `wf_editor_no_review`: open a page with an open request. Expected: the Review button opens the overlay read-only — no Approve, no Reject, no Publish — but "Cancel request for publish" in the banner works, since withdrawing takes `edit`.

## 3. Publishing without a review

10. `wf_publisher` on a page with **no** open request: change the title. Save dropdown. Expected: "Save as draft", "Save and request for publish", "Save and publish" and "Publish". The last two go live directly; `live` is never forced through a review.
11. `wf_author` on the same page: Save dropdown shows only "Save as draft" and "Save and request for publish". No publish route.
12. `wf_publisher` on a page with a **pending** request: Review > footer offers **"Bypass review and publish"**. Confirm. Expected: published without the approvals, and the request recorded as `Published`.

## 4. The blocking check

13. Make `unpublished_references` fail again (relate an unpublished page) on a request that already has both approvals. Expected: "2 of 2 approved" **and** the request still `Pending`, because a blocking check that has not passed holds it. The check row says "Must pass before publishing".
14. Set `blocking: false` for that validator in `config/packages/sulu_content.yaml` and repeat. Expected: the same failure is now informational and the request reaches `Approved`. Note that the flag is snapshotted when the request is created, so change it before requesting.

## 5. Simple case: one approval, no checks

The `simple` workflow in `config/packages/sulu_content.yaml` is the simple case expressed as
configuration: one approval, no pre-validators, no validators. It is selected by the template tag
`sulu_content.request_workflow`, which `config/templates/pages/simple-review.xml` carries.

15. `wf_author`: Pages > add page, template "Simple review", title and URL only, save as draft.
16. `wf_author`: "Save and request for publish". Expected: no pre-validation overlay, form locked at once.
17. `wf_reviewer_one`: Review. Expected: no "Automated checks" card at all, "0 of 1 approved", one "Approval is waiting". Approve. Expected: "1 of 1 approved", banner "Ready to Publish".
18. `wf_author`: Review > Publish. Expected: page live.

## 6. Requests for publishing (Insights)

The Insights tab of a page, article or snippet carries a third sub-tab listing every request ever
made for that content in that locale, newest first, not just the open one.

19. `wf_author`: open a page that by now has a cancelled and a closed request > Insights > "Requests for Publishing". Expected: the tab sits after Versions and Activity; a flat table with Requester and Status, newest on top.
20. Statuses are the four the backend really has: `Pending`, `Approved`, `Cancelled`, `Published`. A bypassed publish is recorded as `Published`, because `bypass_publish` closes the request through the same subscriber as a normal publish.
21. Hover a row and click the ⓘ at its start. Expected: the Review overlay opens read-only for that request, showing its checks, approvals and comments, with no buttons even on a closed request.
22. Switch the locale chooser. Expected: only the requests of the selected locale are listed.
23. Open an article or a snippet that has a request. Expected: the same tab, because all three are built by the same shared factory.

## 7. Optional: validator on a worker

Uncomment the routing line in `config/packages/messenger.yaml`, then:

```bash
bin/adminconsole messenger:setup-transports
bin/adminconsole messenger:consume async -vv
```

Without a worker the check stays "Has not answered yet"; Retry re-queues it. This is why validators
are asynchronous and pre-validators are not: a pre-validator's result is rendered in an overlay in the
same request, so it has to be fast.

## 8. Known gaps

- Request action appears after the first save only.
- `resources` works on the `default` workflow only.
- The "Requests for Publishing" tab is shown on every page, article and snippet, also where no review workflow applies, and is then empty.
- The Figma mock shows a `Bypassed` status; there is no such status, a bypass is recorded as `Published`.
- A blocking check whose validator is no longer registered fails permanently: retry cannot pass it, so the request has to be cancelled or the config fixed.
- Rejection is a vote, not a hand-back: the content stays locked until someone cancels the request.
