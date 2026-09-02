# Upload to zoho.worldofhawas.com

Everything in this folder goes to the **web root of `zoho.worldofhawas.com`** —
the same directory that already serves `preorderendpoint.php`.

The storefront is NOT yet pointed at this host — see step 1. What is left
is putting these files in place and then switching it over.

---

## 1. `preorderendpoint.php` — replace the existing file

Already named to match the file on the server (no hyphens), so it overwrites the
live one rather than sitting beside it as a second copy.

This version fixes pre-orders arriving under the wrong customer's name. The old
one sent the customer's phone number at the top level of the draft order, and
because a phone number is unique across a Shopify store, Shopify used it to
decide who the buyer was — attaching the order to whichever customer already
owned that number and showing *their* name instead of the one on the form.

### This host has no config file yet — this is the blocker

`shopify-preorder.config.php` holds the Admin API token. It is **not** on
`zoho.worldofhawas.com`, and without it the endpoint answers:

```
500 {"ok":false,"error":"Pre-orders are not configured."}
```

The real file is deliberately not in this folder and not in git, so it has to be
copied across by hand from the old host, `zoho.websitedesignersdubai.ae`, where
it sits beside `preorderendpoint.php`. `shopify-preorder.config.example.php` is
included here as the template if you would rather issue a fresh token — save it
as `shopify-preorder.config.php` (drop the `.example`).

The storefront is still pointed at the **old** host until that file is in place.
Repointing it first is what takes pre-orders down — an empty payload returns
`422 "Missing product."` on both hosts, because the endpoint validates the
request before it reads the config, so a 422 is not evidence that the host is
configured. Use the full request below instead.

**Only once this endpoint answers `{"ok":true,...}` should you switch
Theme settings → Pre-orders → endpoint URL over to**
`https://zoho.worldofhawas.com/preorderendpoint.php`.

### Check it afterwards

```bash
curl -s -X POST https://zoho.worldofhawas.com/preorderendpoint.php \
  -H "Content-Type: application/json" \
  -H "Origin: https://gsbjq7-bc.myshopify.com" \
  -d '{"variantId":"45395106463859","product":"Hawas Private Terra","quantity":1,"firstName":"Test","lastName":"Run","email":"test@example.com","phone":"+971500000000","address":"Test Street 1","city":"Dubai","country":"United Arab Emirates"}'
```

`{"ok":true,"order":"#D…"}` is a pass. **Delete the test draft afterwards** —
the client works that list.

---

## 2. `zoho-lead-endpoint.php` — new file

Not on the server yet; it currently returns 404. Uploading it is what connects
the contact form to Zoho CRM.

## 3. `zoho.config.example.php` → `config/zoho.php`

Create a `config/` directory beside the endpoint and save this file there as
**`zoho.php`**, with the real credentials filled in. Do not leave the example
name in place — the endpoint looks for `config/zoho.php`.

The Zoho credentials already exist on the server, inside whatever
`submit-lead.php` reads. Lift them from there rather than issuing new ones.

**Two traps in that config:**

- `accounts_domain` and `api_domain` must both point at the data centre the CRM
  actually lives in — `.in` for a UAE/India account. Point them at the wrong one
  and Zoho returns `invalid_client`.
- `Type_of_Service` is a picklist. The seven options on the contact page must
  match it character for character. They currently do — don't reword either side
  without changing the other.

## 4. Switch it on in Shopify

**Theme settings → Contact form & Zoho CRM → Lead endpoint URL**

```
https://zoho.worldofhawas.com/zoho-lead-endpoint.php
```

Until that setting is filled in, the contact form skips Zoho entirely and only
emails the store. That is deliberate: nothing is lost while Zoho is unset.

Then submit the form on the contact page and confirm the lead lands in Zoho.

**One thing to know when you test it.** The form now submits to Shopify
natively, because the store has Shopify's captcha turned on and the previous
`fetch` copy could not carry the token — Shopify answered
`400 "Missing CAPTCHA token"`, the error was swallowed, and the customer was
still shown a success message. So the page navigates on submit, and the Zoho
call is sent with `keepalive` so it survives that navigation. If you are
watching the network tab, expect the request to complete as the page is already
unloading. A failure there is silent by design: the email to the store is the
record that matters, and it must never be held up by the CRM.

---

## Notes

- Both endpoints only accept requests from `gsbjq7-bc.myshopify.com`,
  `worldofhawas.com` and `www.worldofhawas.com`. Add to `ALLOWED_ORIGINS` /
  `$allowedOrigins` if the storefront ever moves to another domain.
- The old host, `zoho.websitedesignersdubai.ae`, is still up, still configured,
  and is **what the storefront is using right now**. Do not retire it until
  pre-orders are confirmed arriving from the new one.
- The landing page's own `submit-lead.php` cannot be reused for the Shopify
  form: it returns 405 on `OPTIONS`, sends no `Access-Control-Allow-Origin`, and
  requires a `company` field the Shopify form does not collect. That is why
  `zoho-lead-endpoint.php` exists as a separate file.
