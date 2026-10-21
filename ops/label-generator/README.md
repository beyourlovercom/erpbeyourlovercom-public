# BYL PI / Blue Label Generator — Windows Runtime V1

This directory contains the fixed, non-AI blue-label runtime used by the ERP execution chain.

The business source of truth is the confirmed `WarehouseRestockPlan` snapshot.
Codex / Desktop Commander may dispatch the runtime, but they do **not** decide SKU,
quantity, description, packaging data, or label identity.

## Runtime chain

```text
ERP confirmed Restock Plan
  -> Blue Label Job V1 JSON
  -> Codex/DC dispatch
  -> company Windows worker
  -> fixed Node renderer
  -> BarTender 10.1 Picture Master
  -> .btw + exact 50x30 mm .pdf
  -> manifest.json
```

The runtime has no AI-dependent rendering step and does not require an operator to open BarTender.

## Canonical master

Template:

```text
windows/templates/blue-label-picture-master-v1.btw
```

SHA-256:

```text
047e69f0645ac7f20b42a6ac23cdc78e3cf16945d367e6bd80ab713621409a82
```

The master is a clean BarTender 10.1 document with one embedded Picture object.
The runtime replaces that Picture with generated artwork and saves a new independent `.btw`.
The output `.btw` remains reopenable after the temporary PNG is deleted.

BarTender internally rounds the label page to approximately `50.013 x 29.997 mm`.
The companion PDF is created separately with an exact mathematical MediaBox of
`50.00000000 x 30.00000000 mm`.

## Windows prerequisites

- Windows company worker kept online.
- BarTender 10.1 Enterprise Automation.
- Node.js available as `node.exe`.
- Chrome or Edge.
- 32-bit Windows Script Host at `%WINDIR%\SysWOW64\cscript.exe`.
- Desktop Commander / Codex only for dispatch and controlled execution.

No Pillow, npm package install, custom font package, or BarTender manual editing is required.

The label artwork uses system fonts only:

```text
Arial, Segoe UI, sans-serif
```

Barcode format is Code128B. `label_sku` must match `[A-Za-z0-9._-]+`.

## Job contract

Canonical input schema:

```text
contracts/blue-label-job-v1.schema.json
```

Example:

```text
contracts/blue-label-job-v1.example.json
```

Important rules:

- `plan_reference` and `plan_snapshot_checksum` come from the immutable Restock Plan.
- `product_sku` remains the Product OS canonical SKU.
- `label_sku` is the effective blue-label identity and may differ from Product SKU.
- `label_description` is already frozen by ERP; the worker must not invent or rewrite it.
- `restock_qty` must be greater than zero.
- Duplicate `label_sku` values fail closed.

## Manual runtime invocation

From PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\windows\generate-blue-labels.ps1 -JobPath .\contracts\blue-label-job-v1.example.json -OutputDir C:\Temp\BL-UAT-001
```

A successful run prints:

```text
BLUE_LABEL_JOB_OK job_id=<id> items=<n> output=<path>
```

Outputs are immutable. If `OutputDir` already exists, the runtime fails instead of overwriting it.

## Output contract

Canonical output schema:

```text
contracts/blue-label-output-v1.schema.json
```

Example structure:

```text
BL-UAT-001/
  manifest.json
  labels/
    C549A.btw
    C549A.pdf
    CA755B.btw
    CA755B.pdf
```

`manifest.json` records the job identity, Restock Plan identity, template hash,
runtime evidence, per-file SHA-256 hashes, label size, artwork dimensions, barcode type,
and the frozen restock quantity.

## Validation gates completed for M4

- Node core tests: Code128B, safe label identity, Chinese wrapping, 50x30 SVG contract, and exact PDF MediaBox.
- Windows batch UAT: 2 SKUs generated in one job.
- Real BarTender 10.1 automation: PASS.
- Temporary artwork deletion followed by independent `.btw` reopen/export: PASS.
- CoreGraphics PDF verification: `C549A.pdf` and `CA755B.pdf` are each 1 page at exactly `50.00000000 x 30.00000000 mm`.
- Existing output directory overwrite: blocked.
- Duplicate label SKU: blocked before any output directory is created.

Production ERP dispatch, job queue state, R2 upload, ZIP packaging, and retry/heartbeat
belong to the later M5–M8 integration stages and are intentionally not implemented here.

## M6 ERP worker dispatch

The ERP exposes a machine-only protocol under:

    /api/internal/blue-label-worker

Authentication requires both a Bearer token and a stable X-BYL-Worker-ID.

The browser never receives this token. The Worker token is configured only through server/worker
secret storage and is deliberately absent from tracked configuration.

State transitions:

    queued -> claimed -> running -> succeeded
                           \-> failed

The ERP enforces worker ownership and an expiring lease. A Worker cannot start, heartbeat,
complete, or fail a job claimed by a different Worker. Completion also revalidates the output
manifest against the frozen Restock Plan job payload, including Label SKU, Product SKU,
restock quantity, Code128B, exact 50x30 mm size, and per-file SHA-256 evidence.

### Windows entry point

The preferred M6 entry point is Node:

    windows/dispatch-once.cjs

It reads BYL_BLUE_LABEL_WORKER_TOKEN from the Worker process environment and performs one bounded
iteration:

    claim -> start -> heartbeat -> fixed generator -> heartbeat -> complete/fail

If there is no queued job, it exits successfully with BLUE_LABEL_DISPATCH_IDLE.

The Node batch generator is:

    windows/generate-blue-labels.cjs

This Node path exists because the validated company Windows currently blocks direct .ps1 execution
by PowerShell policy. M6 does not weaken or modify that security policy.

### M6 isolated full-chain UAT

A temporary SQLite ERP instance and the company Windows were used; no Production data was touched.

Evidence:

    job: BLJ-M6UAT-20260920-WFYT60Q7NH
    items: 2
    worker: company-win-label-01
    attempt_count: 1
    result: succeeded
    outputs: 2 BTW + 2 exact-50x30 PDF + manifest
    second dispatch: BLUE_LABEL_DISPATCH_IDLE

The Windows run used the real installed BarTender runtime and returned the completion manifest
through the M6 Worker API. The temporary test token, SQLite database, HTTP services, and output
directory are UAT-only and are not part of Production configuration.

## M7 PI and ERP package archive

M7 upgrades a successful worker result from "files exist on Windows" to "operators can download a complete ERP-owned package".

Canonical PI template:

    templates/007-PI-sea-canonical-v1.xlsx

Canonical template SHA-256:

    e117023f3955bad37860f13f3c2370ae16334df86270a43122131950814e91f1

The workbook is the uploaded real 007 sea-freight PI workbook. V1 preserves its workbook structure, styles,
hidden sheets, formulas, and layout. Only the PI worksheet data slots are rewritten.

PI V1 supports rows 5 through 22, therefore at most 18 SKUs. A larger job fails closed instead of
silently expanding or redesigning the template.

Frozen PI mapping:

    B  Label SKU
    C  Product name
    D  final restock quantity
    E  units per carton
    F  carton count
    G  L×W×H cm only when all dimensions are authoritative
    H  packaged weight only when authoritative
    K  frozen available inventory

Unit price, subtotal, in-transit quantity, 30-day sales, and sellable-days remain blank in V1 when the
confirmed Restock Plan does not contain an authoritative field with the same business definition.
120-day shipment volume is never substituted for 30-day sales.

### Artifact return to ERP

The Worker uploads every generated BTW and PDF back to the authenticated ERP Worker API before calling
complete. Each upload carries its manifest SHA-256 and is verified again by ERP.

ERP private storage layout:

    inventory/restock-documents/<job-reference>/
      PI.xlsx
      manifest.json
      blue-labels/
        <label-sku>.btw
      sku-labels/
        <label-sku>.pdf
      <job-reference>.zip

The downloadable ZIP contains:

    PI.xlsx
    manifest.json
    蓝标/<label-sku>.btw
    sku标签/<label-sku>.pdf

The job only becomes succeeded after all expected worker artifacts have arrived, their SHA-256 values
match the worker manifest, PI generation succeeds, and the final ZIP is created.

Warehouse Restock UI then exposes two private authenticated downloads:

    下载 PI
    下载完整包

### M7 isolated full-chain UAT

No Production data was touched.

Temporary SQLite ERP + real company Windows + installed BarTender generated and returned:

    job: BLJ-M7UAT-20260922-JTT8WDAEBR
    plan: RP-M7-UAT-001
    worker: company-win-label-01
    items: 2
    status: succeeded
    attempt_count: 1

ERP received:

    C549A.btw
    C549A.pdf
    CA755B.btw
    CA755B.pdf
    PI.xlsx
    manifest.json
    BLJ-M7UAT-20260922-JTT8WDAEBR.zip

PI evidence included:

    B2 = RP-M7-UAT-001
    B3 = 2026/09/22
    B5 = C549A
    C5 = VC549A 磁吸穿戴裸机玫红色成品 APP款
    D5 = 10
    E5 = 5
    F5 = 2
    G5 = 20×10×5 cm
    H5 = 0.3
    K5 = 2
    D24 = SUM(D5:D22), cached total 13

The second SKU intentionally lacked packaging evidence; its packaging cells remained blank instead of
being guessed.

All temporary HTTP services, SQLite data, Windows output, and UAT files were removed after verification.

## M8 worker reliability and continuous operation

The Windows worker can now run continuously through:

    windows/worker-loop.cjs

Default behavior:

    poll -> claim -> dispatch -> poll again

If the queue is empty the worker exits each bounded dispatcher iteration with
BLUE_LABEL_DISPATCH_IDLE and sleeps before the next poll.

### Heartbeat

The dispatcher sends heartbeats while the fixed generator child process is still running.
Production default heartbeat interval is 30 seconds. The interval is configurable only for
controlled UAT through --heartbeat-ms.

### Retry and lease recovery

ERP now tracks next_attempt_at and uses bounded exponential backoff.

Default policy:

    max attempts: 3
    retry base: 30 seconds
    delay: 30s, 60s, 120s ... capped at 3600s

Retryable worker failures:

    dispatch_failure
    worker_crash
    heartbeat_failure

When a retry is scheduled:
- worker ownership is released;
- the previous lease is cleared;
- unfinished ERP artifact files are deleted;
- the immutable Restock Plan job payload remains unchanged;
- the next worker attempt receives the same job reference and frozen business data;
- Windows output uses a separate attempt-N directory.

Expired claimed/running leases are recovered automatically when a worker polls the queue.
If max attempts are exhausted, the job becomes terminal failed with worker_lease_exhausted.

### Long-running worker contract

A production worker process must receive BYL_BLUE_LABEL_WORKER_TOKEN through its process
environment. The token is never stored in Git, never rendered to the ERP browser, and never
passed in the normal command line.

Example:

    node worker-loop.cjs --api https://erp.beyourlover.com/api/internal/blue-label-worker --runtime C:\BYL\blue-label-runtime --output-root C:\BYL\blue-label-output --worker company-win-label-01

The worker loop is deterministic infrastructure. Codex/DC may install, inspect, restart, or
repair it, but label generation itself does not require AI reasoning.

### M8 isolated continuous-worker UAT

Temporary SQLite ERP + real company Windows + installed BarTender:

    job: BLJ-M8UAT-20260922-FJZ0KEKA37
    worker: company-win-label-01
    loop iterations: 3
    iteration 1: BLUE_LABEL_DISPATCH_OK
    iteration 2: BLUE_LABEL_DISPATCH_IDLE
    iteration 3: BLUE_LABEL_DISPATCH_IDLE
    final status: succeeded
    attempt_count: 1

During generation, the temporary ERP observed repeated heartbeat requests before artifact upload
and completion. No Production data or Production token was used.
