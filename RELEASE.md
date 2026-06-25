# Releasing CH Tryout Registration

This plugin updates itself from **GitHub Releases** (same approach as Puck Press).
No build step — a release just means bump the version, push, and publish a
release marked **Latest**.

## How updates reach the live sites

`inc/update.php` (`CH_Tryout_Updater`) polls:

```
https://api.github.com/repos/connormesec/ch-tryout-registration/releases/latest
```

`/releases/latest` returns **only** the release you mark as
*“Set as the latest release.”* It compares that tag against the installed
`CH_TRYOUT_VERSION` and, when newer, shows the normal WordPress
**“update available”** prompt on the Plugins screen. One click installs the
release zip. Drafts and pre-releases are ignored, so nothing is pushed to sites
until you flip a release to latest.

The GitHub response is cached for 6 hours per site (protects the shared host
IP's API rate limit). WordPress checks roughly every 12 hours; **Dashboard →
Updates → “Check again”** forces an immediate re-poll.

## Cutting a release

1. **Bump the version in two places — they must match:**
   - `ch-tryout-registration.php` header: `* Version: X.Y.Z`
   - `ch-tryout-registration.php` constant: `define( 'CH_TRYOUT_VERSION', 'X.Y.Z' );`
2. Commit and push to `main`.
3. Tag and publish a release **marked as latest** (bare version tag, no `v` —
   though a `v` prefix is tolerated):

   ```sh
   git tag X.Y.Z && git push --tags
   gh release create X.Y.Z --title "X.Y.Z" --notes "What changed" --latest
   ```

   The release notes (`body`) appear in the plugin's **View details** popup.

That's it — sites will prompt to update on their next check.

## Versioning

`X.Y.Z`, SemVer-inspired:
- **Patch** (x.y.**Z**): fixes, small features, template/CSS changes.
- **Minor** (x.**Y**.0): new subsystems.
- **Major** (**X**.0.0): breaking DB/schema or behavior changes.

## Notes

- **Folder rename:** GitHub's source zip unpacks to
  `connormesec-ch-tryout-registration-<sha>/`; `fix_source_dir()`
  (`upgrader_source_selection`) renames it to `ch-tryout-registration/` so the
  install lands in the right place. To skip that, attach a pre-structured
  `.zip` asset to the release — the updater prefers a single attached `.zip`
  over the source zipball.
- **Private repo / rate limits:** the repo is public, so no token is needed. If
  it ever goes private, define a PAT (Contents: read) in `wp-config.php`:
  `define( 'CH_TRYOUT_GH_TOKEN', 'github_pat_...' );`
- **Two version fields must stay in sync.** The header drives what WordPress
  displays; the constant drives the update comparison and the User-Agent.
