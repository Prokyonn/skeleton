# Test "Request for publish"

This branch of the skeleton is wired for the `feature/workflow-transition-request` branch of `Prokyonn/sulu`
(seven commits on top of Sulu 3.1): admin route import, workflow config, the `review` accounts command, a demo
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
3. `wf_reviewer_one`: open the page. Expected: banner with "Cancel request for publish", which a reviewer may use to hand the content back. Approval (save icon) > Review: "0 of 3 approved", the validator row rejected with the unpublished page named, two "Approval is waiting" rows. Approve (comment optional). Expected: overlay stays open, "1 of 3 approved", Approve disabled as "You approved". Hover the dot: tooltip lists the approval.
4. `wf_reviewer_one`: Reject. Expected: Send disabled until a comment is typed. Send. Expected: "0 of 3 approved, 1 rejected", comment expandable. Approve again. Expected: "1 of 3 approved".
5. `wf_editor_no_review`: open the page. Expected: no Approval dropdown.
6. `wf_publisher`: publish the related page that was only a draft. `wf_reviewer_two`: open the request, click Retry on the validator row. Expected: validator row approved, "2 of 3 approved". Approve. Expected: "3 of 3 approved", banner "Ready to Publish".
7. `wf_publisher`: Approval > Publish enabled, Bypass disabled. Publish. Expected: green dot, page live, request closed.
8. `wf_author`: change the title of the published page. Expected: "Save and request for publish" enabled. Click it. Expected: draft saved and request opened in one step. Banner > "Cancel request for publish". Expected: form unlocked.
9. `wf_author`: new page with SEO and excerpt, request. `wf_publisher`: Approval > "Bypass review and publish", confirm. Expected: published without approvals.
10. While a request is open: copy locale into the locked locale or restore a version. Expected: refused, "content is in review".
11. `wf_author`: new page with SEO and excerpt, request. `wf_editor_no_review`: open it. Expected: banner without cancel. `wf_reviewer_one`: open it, banner > "Cancel request for publish". Expected: request cancelled, form unlocked for the author again.

## 3. Simple case: one approval, no checks

The `simple` workflow in `config/packages/sulu_content.yaml` is the simple case expressed as
configuration: one approval, no pre-validators, no validators. It is selected by the template tag
`sulu_content.request_workflow`, which `config/templates/pages/simple-review.xml` carries.

1. `wf_author`: Pages > add page, template "Simple review", title and URL only, save as draft.
2. `wf_author`: "Save and request for publish". Expected: no SEO or excerpt error, form locked at once.
3. `wf_reviewer_one`: Approval > Review. Expected: "0 of 1 approved", no validator row, one "Approval is waiting". Approve. Expected: "1 of 1 approved", banner "Ready to Publish".
4. `wf_publisher`: Approval > Publish. Expected: page live.

What still differs from the Figma "Simpler Case": Approve and Reject live inside the Review overlay
rather than as direct dropdown items, and a rejection is a vote that leaves the content locked until
someone cancels the request rather than sending it back to the author.

## 4. Requests for publishing (Insights)

The Insights tab of a page, article or snippet carries a third sub-tab listing every request ever
made for that content in that locale, newest first, not just the open one.

1. `wf_author`: take the page from section 2, which by then has one cancelled and one closed request. Open it > Insights > "Requests for Publishing". Expected: tab sits after Versions and Activity; a flat table with Requester and Status, newest request on top.
2. Statuses are the four the backend really has: `Pending`, `Approved`, `Cancelled`, `Published`. A bypassed publish is recorded as `Published`, because `bypass_publish` closes the request through the same subscriber as a normal publish.
3. Hover a row and click the ⓘ at its start. Expected: the Review overlay opens read-only for that request, showing its approvals and checks and comments, with no Approve/Reject buttons even on a closed request.
4. Switch the locale chooser. Expected: only the requests of the selected locale are listed.
5. Open an article or a snippet that has a request. Expected: the same tab, because all three are built by the same shared factory.
6. `wf_editor_no_review`: open a page with requests. Expected: the tab is readable, since it needs only the `view` permission the form already requires.

## 5. Optional: validator on a worker

Uncomment the routing line in `config/packages/messenger.yaml`, then:

```bash
bin/adminconsole messenger:setup-transports
bin/adminconsole messenger:consume async -vv
```

Without a worker the validator row stays "waiting"; Retry re-queues it.

## 6. Known gaps

- Request action appears after the first save only.
- `resources` works on the `default` workflow only.
- The "Requests for Publishing" tab is shown on every page, article and snippet, also where no review workflow applies, and is then empty.
- The Figma mock shows a `Bypassed` status; there is no such status, a bypass is recorded as `Published`.
