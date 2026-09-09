# Test "Request for publish"

This skeleton branch is wired to the `feature/workflow-transition-request` branch of `Prokyonn/sulu`
so the review flow can be clicked through end to end.

## The model

- **People approve, checks report.** Only human approvals count towards `required_human_approvals`.
- A check marked `blocking: true` holds the request until it passes, however many approvals are in.
- **`live`** publishes on its own authority, with or without a request.
- **`edit`** publishes only what reviewers signed off, and only from inside the review overlay.
- **Pre-validators** are synchronous hard gates on every route to live. **Validators** run on the bus.

## Setup

```bash
git clone --branch feature/workflow-transition-request git@github.com:Prokyonn/skeleton.git wtr-test && cd wtr-test
composer install                        # composer.lock is committed, so you get the tested sulu commit
# set DATABASE_URL in .env.local
bin/adminconsole sulu:build dev
bin/adminconsole doctrine:migrations:migrate --no-interaction
bin/adminconsole app:setup-review-users
symfony server:start -d
```

> **Tested an earlier state of this branch?** The tables were renamed. Drop `wt_workflow_transition_requests`
> and `wt_workflow_transition_request_reviewers` before migrating: the migration guards on `hasTable()`
> and will not convert them.

**Accounts**, password `test`:

| Account | Permissions | Its job in the tests |
| --- | --- | --- |
| `wf_author` | view, add, edit | Requests a publish, publishes it once approved |
| `wf_reviewer_one`, `wf_reviewer_two` | view, edit, review | Approve and reject |
| `wf_publisher` | view, add, edit, review, live | Publishes directly, bypasses a pending request |
| `wf_editor_no_review` | view, edit | Sees the overlay read-only, may cancel |

**Configured workflows** (`config/packages/sulu_content.yaml`):

| Workflow | Applies to | Approvals | Pre-validators | Validators |
| --- | --- | --- | --- | --- |
| `default` | pages, articles | 2 | `seo_required`, `excerpt_required` | `unpublished_references` (blocking) |
| `simple` | templates carrying the tag | 1 | none | none |

## A. Main path

Author requests, two reviewers approve, the author publishes without holding `live`.

| # | As | Do | Expect |
| --- | --- | --- | --- |
| 1 | `wf_author` | Add a page, save as draft, then "Save and request for publish" with SEO and excerpt empty | Overlay "Content is not ready to go live", one row per check, "1 of 2 passed". No request written, form still editable |
| 2 | `wf_author` | Fill SEO title, SEO description, excerpt title. Relate a draft-only page. Request again | Form locked, yellow banner with "Cancel request for publish", yellow header dot |
| 3 | `wf_author` | Open the **Review** button (one button now, not a dropdown) | Two cards: "Automated checks" with a red `unpublished_references` reading "Must pass before publishing", and "0 of 2 approved". No Approve or Reject, but a **Retry** link |
| 4 | `wf_publisher`, then `wf_author` | Publish the related page, then Review > Retry | Check turns green, "Passed", "1 of 1 passed" |
| 5 | `wf_reviewer_one` | Review > Approve with a comment | Overlay stays open, "1 of 2 approved", Approve disabled as "You approved", comment in a speech bubble clamped to three lines |
| 6 | `wf_reviewer_one` | Reject, then approve again | Send stays disabled until a comment is typed. "0 of 2 approved, 1 rejected", then "1 of 2 approved" |
| 7 | `wf_reviewer_two` | Approve | "2 of 2 approved", banner turns "Ready to Publish" |
| 8 | `wf_author` | Review > **Publish** (in the overlay footer) | Page live, request closed, banner gone |
| 9 | `wf_editor_no_review` | Open a page with an open request | Overlay read-only: no Approve, Reject or Publish. "Cancel request for publish" still works |

> Step 8 is the point of the whole flow: the approvals delegated the publish right to an author who
> holds no `live`. Step 9 is the other half: cancelling is not a verdict, so it takes `edit`.

## B. Publishing without a review

| # | As | Do | Expect |
| --- | --- | --- | --- |
| 10 | `wf_publisher` | Page with **no** request: change the title, open the Save dropdown | Four entries: "Save as draft", "Save and request for publish", "Save and publish", "Publish". The last two go live directly |
| 11 | `wf_author` | Same page, Save dropdown | Only "Save as draft" and "Save and request for publish". No publish route |
| 12 | `wf_publisher` | Page with a **pending** request: Review > "Bypass review and publish" | Published without the approvals, request recorded as `Published` |

## C. The blocking check

| # | Do | Expect |
| --- | --- | --- |
| 13 | Relate an unpublished page on a request that already has both approvals | "2 of 2 approved" **and** still `Pending`. The row reads "Must pass before publishing" |
| 14 | Set `blocking: false` for that validator, then request again | The same failure is now informational, the request reaches `Approved` |

> The flag is snapshotted onto the check when the request is created, so change it before requesting.
> `required_human_approvals: 0` is refused at container build unless a validator is `blocking: true`,
> since it would otherwise approve every request on arrival.

## D. Simple workflow: one approval, no checks

Selected by the template tag `sulu_content.request_workflow`, which `config/templates/pages/simple-review.xml` carries.

| # | As | Do | Expect |
| --- | --- | --- | --- |
| 15 | `wf_author` | Add a page with template "Simple review", title and URL only, save as draft | Saves like any other page |
| 16 | `wf_author` | "Save and request for publish" | No pre-validation overlay, form locked at once |
| 17 | `wf_reviewer_one` | Review > Approve | No "Automated checks" card at all. "0 of 1 approved" then "1 of 1 approved", banner "Ready to Publish" |
| 18 | `wf_author` | Review > Publish | Page live |

## E. Requests list (Insights)

A third Insights sub-tab lists every request ever made for that content in that locale, not just the open one.

| # | Do | Expect |
| --- | --- | --- |
| 19 | Open a page with a cancelled and a closed request > Insights > "Requests for Publishing" | Tab sits after Versions and Activity. Flat table, Requester and Status, newest first |
| 20 | Read the statuses | Only the four the backend has: `Pending`, `Approved`, `Cancelled`, `Published`. A bypass is recorded as `Published` |
| 21 | Hover a row, click the ⓘ | Review overlay opens read-only, with checks, approvals and comments, no buttons |
| 22 | Switch the locale chooser | Only the selected locale's requests are listed |
| 23 | Open an article or snippet that has a request | The same tab, built by the same shared factory |

## Optional: run a validator on a worker

```bash
# uncomment the routing line in config/packages/messenger.yaml first
bin/adminconsole messenger:setup-transports
bin/adminconsole messenger:consume async -vv
```

Without a worker the check stays "Has not answered yet" and Retry re-queues it. This is why validators
may be asynchronous and pre-validators may not: a pre-validator's result is rendered in an overlay in
the same request.

## Known gaps

- The request action appears after the first save only.
- `resources` works on the `default` workflow only.
- The "Requests for Publishing" tab shows on every page, article and snippet, and is empty where no workflow applies.
- The Figma mock shows a `Bypassed` status. There is none, a bypass is recorded as `Published`.
- A blocking check whose validator is no longer registered fails permanently: retry cannot pass it, so the request has to be cancelled or the config fixed.
- Rejection is a vote, not a hand-back: the content stays locked until someone cancels.
