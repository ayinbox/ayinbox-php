# Publishing to Packagist

Packagist has no trusted publishing. It reads versions straight from the Git tags of the GitHub repository, so nothing is uploaded and no token lives in this repo or its workflows. `.github/workflows/release.yml` only checks the tag, runs the tests and creates the GitHub release.

## One-time setup

1. Make `ayinbox/ayinbox-php` public. Packagist cannot read a private repository.
2. Sign in to https://packagist.org with the account that will own the package (ideally one tied to the `ayinbox` GitHub organisation, through "Login with GitHub").
3. Claim the vendor name: the first package submitted under `ayinbox/` reserves the `ayinbox` vendor for that account.
4. Go to https://packagist.org/packages/submit, enter `https://github.com/ayinbox/ayinbox-php` and submit. Packagist reads `composer.json` and creates `ayinbox/ayinbox`.
5. Keep it updated on every push and tag. Either:
   - Preferred: on Packagist, open your profile and connect GitHub (Settings, "Connected accounts"). With organisation access granted, Packagist installs the hook on `ayinbox/ayinbox-php` itself. The package page stops showing the "not auto-updated" warning once it works.
   - Manual: in GitHub, `ayinbox/ayinbox-php` Settings, Webhooks, Add webhook. Payload URL `https://packagist.org/api/github?username=<packagist-username>`, content type `application/json`, secret: the API token from your Packagist profile page, event: "Just the push event". This token is entered in GitHub's webhook settings only, never committed.
6. Add other maintainers on the package page if needed.

## Releasing a version

1. Set `VERSION` in `src/AyInbox.php` and add the entry to `CHANGELOG.md`. Commit to `main`.
2. Tag and push:

   ```sh
   git tag v0.1.0
   git push origin v0.1.0
   ```

3. The release workflow fails if the tag and `AyInbox::VERSION` differ. When it passes it creates the GitHub release, and the webhook tells Packagist about the tag within a minute.
4. Once the first version is on Packagist, change the README install section to:

   ```sh
   composer require ayinbox/ayinbox
   ```

Packagist versions come from tags, so `composer.json` carries no `version` field. A tag pushed by mistake is removed by deleting it on GitHub and pressing "Update" on the package page.
