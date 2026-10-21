# Browser E2E Foundation

This directory contains the local-only `ERP-ARCH-MIGRATION-001E-B` browser harness.

## Safety boundary

- Run only through `npm run test:e2e`; `test:e2e:direct` is an internal command that requires an already validated environment.
- Every run creates a fresh owner-only directory under the operating-system temporary directory.
- The disposable SQLite database, generated secrets, reports and Playwright output remain under that external run root and are deleted on every exit path.
- The runner refuses repository-contained paths, non-SQLite or shared databases, non-testing environments and non-loopback URLs before Migration or user provisioning.
- `ERP_LOCAL_ADMIN_BYPASS=false` is enforced.
- Screenshot, trace and video capture are disabled. Storage state is never generated. Nothing is uploaded.
- Only Chromium is configured. Authentication, permission, 403/404, logout and App Shell scenarios belong to 001E-C.

## Commands

```text
npm run test:e2e:contracts
npm run typecheck:e2e
PLAYWRIGHT_BROWSERS_PATH=/absolute/external/versioned/cache npm run test:e2e
```

The browser cache path must be absolute, outside the repository and dedicated to Playwright `1.62.1`.
