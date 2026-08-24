# Company directory example

This example implements a complete Kern workflow for a public company directory:

1. an authenticated user requests ownership of a company Page or redeems an access code;
2. a moderator approves a normal claim in **Setup → Kern**;
3. the user sees the company in a private account dashboard;
4. the user proposes changes to `title` and `summary`;
5. a moderator reviews and approves the revision before the live company Page changes.

The example is deliberately framework-neutral HTML. Adapt the paths and markup to the consuming site's frontend.

## ProcessWire setup

Create or reuse a `company` template with these fields:

- `title` — Text;
- `summary` — Textarea.

Create three frontend Pages and assign the corresponding template files:

| Page | Template file |
| --- | --- |
| `/claim-company/` | `claim-company.php` |
| `/account/companies/` | `my-companies.php` |
| `/account/company-edit/` | `edit-company.php` |

Adjust the hard-coded account and login paths in the files when the site uses different routes.

## Kern access rules

Create two enabled rules under **Setup → Kern → Access rules**.

### 1. Company claim requests

- Page templates: `company`
- Audience: authenticated users
- Allow actions: `request_claim`
- Claim moderation: required
- Revision moderation: inherit

Do not grant `edit` in this rule.

### 2. Active company owners

- Page templates: `company`
- Allowed fields: `title`, `summary`
- Audience: active claimants
- Allow actions: `edit`
- Revision moderation: required

This separation lets any authenticated user request ownership while only an approved claimant can propose edits.

## Access-code onboarding

Instead of approving a normal request, a trusted administrator may issue a one-time code from **Setup → Kern → Access codes**. The same `claim-company.php` template accepts that code and activates the claim immediately.

Codes should be delivered through an approved secure channel. Kern returns plaintext only once and stores only a keyed hash and short hint.

## Integration boundaries

- These files assume the site already provides registration, login and account access control.
- Every POST is protected with ProcessWire CSRF validation.
- Every request re-checks Kern authorization; a link rendered earlier is not treated as permission.
- The edit handler never calls `$company->save()`. It submits a Kern revision.
- This example handles scalar fields only. Uploads and creation of structured items require project-specific staging and an adapter.
- Production projects should add their normal rate limits, proof-of-ownership process, notifications and error presentation.
