# GHL Widgets

A centralized repository of dashboard widgets for the [GoHighLevel (GHL)](https://www.gohighlevel.com/) platform, managing analytics and reporting across **59 sub-accounts**.

All widgets are embedded as iframes inside GHL dashboards and pass `?locationId=` in the URL to identify the account.

---

## Table of Contents

- [Architecture](#architecture)
- [Widgets](#widgets)
- [Project Structure](#project-structure)
- [How Data Flows](#how-data-flows)
- [Local Development](#local-development)
- [Deploying to AWS](#deploying-to-aws)
- [Updating Widgets on S3](#updating-widgets-on-s3)
- [Adding a New Tenant](#adding-a-new-tenant)
- [Rotating a GHL Token](#rotating-a-ghl-token)
- [AWS Resources](#aws-resources)

---

## Architecture

```
GHL Dashboard (iframe)
    └─→ CloudFront (HTTPS CDN)                 https://d1u9vpyrlpzvbr.cloudfront.net
          └─→ S3 bucket (mnc-widgets-static)   static HTML + ghlDataV3.js
                └─→ ghlDataV3.js (browser)
                      └─→ IndexedDB (20-min local cache)
                            └─→ API Gateway                https://riedh3l031.execute-api.ap-southeast-1.amazonaws.com
                                  └─→ Lambda functions
                                        ├─→ DynamoDB (server-side cache)
                                        ├─→ SSM Parameter Store (GHL tokens)
                                        └─→ GHL API (on cache miss)

EventBridge (every 20 min)
    └─→ Lambda: ghl-cache-warmer
          └─→ Pre-warms all 59 tenants in DynamoDB
```

**Why Lambda instead of direct GHL calls?**
- GHL Bearer tokens for all 59 tenants are stored securely in AWS SSM — never in the browser
- Server-side DynamoDB cache means widgets load instantly (cache hit) instead of waiting for GHL's paginated API
- Rate limiting (429 errors) is handled server-side with retry logic — browser never stalls
- One EventBridge warmer keeps cache fresh for all tenants every 20 minutes

---

## Widgets

### Bar (`bar/`)

| File | Description |
|---|---|
| `bar_indexedDB.html` | Leads by hour — bar chart of opportunity creation times |
| `bar_indexedDB_days.html` | Leads by day of week |
| `leads_enquiry_stats.html` | Lead enquiry statistics with contact details |
| `source_last_entry.html` | Most recent lead per source |
| `stacked_bar_deal_performance.html` | Stacked bar chart of deal performance by pipeline stage |

### Donut (`donut/`)

| File | Description |
|---|---|
| `donut_project_source_indexedDB.html` | Donut chart — opportunities by project + source |
| `donut_project_user_indexedDB.html` | Donut chart — opportunities by project + assigned user |
| `csv_donut_project_source_indexedDB.html` | Same as above with CSV export |
| `csv_donut_project_user_indexedDB.html` | Same as above with CSV export |

### Funnel (`funnel/`)

| File | Description |
|---|---|
| `funnel_widget_indexDB.html` | Full funnel — opportunities across all pipeline stages |
| `funnel_current_stage.html` | Current stage snapshot — where leads are right now |
| `funnel_one_chart.html` | Single pipeline funnel chart |

### Report (`report/`)

| File | Description |
|---|---|
| `sales_activity_report.html` | Sales activity report — conversations, contacts, appointments per rep |
| `sales_activity_report_lsh.html` | SAR variant for LSH location |
| `sales_activity_report_mkh.html` | SAR variant for MKH location (includes opportunity flow tab) |
| `deal_performance_report.html` | Deal performance across pipelines |
| `campaign_report.html` | Campaign-level reporting |
| `opportunities.html` | Opportunities table with filters |
| `opportunities_latest.html` | Latest opportunities sorted by creation date |
| `enhanced_opportunities.html` | Opportunities with enhanced custom field display |
| `project_source_report.html` | Opportunities grouped by project and source |
| `report_widget.html` | User × pipeline opportunity count table |
| `mkh_table.html` | MKH-specific table with conversation data |
| `mkh_excel_report.html` | MKH report with Excel export |

### Call Chart (`call_chart/`)

| File | Description |
|---|---|
| `call_chart1.html` | Call volume chart — inbound/outbound/missed by agent |
| `call_report1.html` | Detailed call report table with duration and disposition |

### CRM Tracker (`crm-tracker/`)
PHP-based backend dashboard. Uses its own `dashboard/api/*.php` files and `GhlClient.php`. **Not part of the Lambda migration** — runs separately on a PHP server.

---

## Project Structure

```
widgets/
├── ghlDataV3.js                  ← shared data library used by all widgets
│                                    fetches from Lambda, caches in IndexedDB
│
├── bar/                          ← bar chart widgets
├── donut/                        ← donut chart widgets
├── funnel/                       ← funnel widgets
├── report/                       ← report widgets
├── call_chart/                   ← call analytics widgets
│
├── lambda/                       ← AWS Lambda backend
│   ├── template.yaml             ← SAM deployment template
│   ├── samconfig.toml            ← SAM deploy config (region, stack name)
│   ├── layer/nodejs/lib/
│   │   ├── secrets.js            ← reads GHL tokens from SSM Parameter Store
│   │   ├── dynamo.js             ← DynamoDB cache get/set/delete
│   │   └── ghl-client.js        ← GHL API calls with pagination + 429 retry
│   ├── ghl-data-proxy/           ← main API proxy (pipelines, users, opps, etc.)
│   ├── ghl-config-api/           ← returns public tenant config (no tokens)
│   ├── call-data-proxy/          ← call CDR data proxy
│   └── ghl-cache-warmer/         ← EventBridge-triggered cache pre-warmer
│
├── scripts/
│   └── migrate-to-ssm.js         ← one-time script: uploads tokens to SSM
│
├── crm-tracker/                  ← PHP-based CRM dashboard (separate system)
├── visitor_qr/                   ← PHP visitor QR system
├── engage/, WA/, automation/     ← other PHP integrations
│
├── AWS_GUIDE.md                  ← detailed explanation of every AWS service used
└── README.md                     ← this file
```

---

## How Data Flows

### First load (cold — no cache)

```
1. Browser opens widget with ?locationId=abc
2. ghlDataV3.js checks IndexedDB → empty
3. Calls Lambda via API Gateway: GET /ghl/opportunities?locationId=abc
4. Lambda checks DynamoDB → not found (cache miss)
5. Lambda reads GHL token from SSM Parameter Store
6. Lambda calls GHL API with token → paginates through all results
7. Lambda saves result to DynamoDB with TTL (20 min for opportunities)
8. Lambda returns data with header X-Cache: MISS
9. ghlDataV3.js saves to IndexedDB with timestamp
10. Widget renders
```

### Repeat load (warm cache)

```
1. Browser opens widget — same locationId
2. ghlDataV3.js checks IndexedDB → found, < 20 min old → renders immediately
   (no network call at all)
```

### After 20 minutes (IndexedDB stale, DynamoDB still warm)

```
1. ghlDataV3.js checks IndexedDB → stale, discards it
2. Calls Lambda: GET /ghl/opportunities?locationId=abc
3. Lambda checks DynamoDB → found, not expired (cache hit)
4. Lambda returns data with header X-Cache: HIT  (fast, ~50ms)
5. Widget renders
```

### EventBridge warmer (background, every 20 min)

```
EventBridge fires → ghl-cache-warmer Lambda runs
→ Loops through all 59 tenants in groups of 5
→ Fetches pipelines, users, and all opportunities per pipeline from GHL
→ Saves to DynamoDB (refreshes TTL)
→ Next widget load always hits DynamoDB cache, never GHL directly
```

---

## Local Development

### Requirements

- [XAMPP](https://www.apachefriends.org/) (Apache for serving HTML locally)
- [Node.js 18+](https://nodejs.org/)
- [AWS SAM CLI](https://docs.aws.amazon.com/serverless-application-model/latest/developerguide/install-sam-cli.html)
- [AWS CLI](https://aws.amazon.com/cli/) configured with your credentials

### Run widgets locally

1. Start XAMPP → Apache
2. Open any widget:
   ```
   http://localhost/widgets/funnel/funnel_widget_indexDB.html?locationId=WphrMU0x3Ocd2pEpBJcH
   ```
   The widget calls the live Lambda API Gateway — no local Lambda needed for frontend testing.

### Run Lambda locally (optional)

```bash
cd lambda
npm install
sam build
sam local start-api --env-vars env.json
```

Create `lambda/env.json` from `lambda/env.example.json` and fill in your values. With `LOCAL_DEV=true`, Lambda reads tokens from env vars instead of SSM.

### Test a specific Lambda endpoint

```bash
# Test config API
curl "http://localhost:3000/config?locationId=WphrMU0x3Ocd2pEpBJcH" \
  -H "X-Api-Key: your-local-api-key"

# Test pipelines
curl "http://localhost:3000/ghl/pipelines?locationId=WphrMU0x3Ocd2pEpBJcH" \
  -H "X-Api-Key: your-local-api-key"
```

### Test tenants

| Tenant | locationId | Notes |
|---|---|---|
| Demo | `WphrMU0x3Ocd2pEpBJcH` | Small dataset — safe for frequent testing |
| PV | `rsdW1sEFWbzzULIapmdQ` | Large dataset — use for load/stress testing |

---

## Deploying to AWS

### First-time setup

```bash
# 1. Install dependencies
cd lambda/layer/nodejs && npm install
cd ../../..

# 2. Upload all tenant tokens to SSM (run once)
cd scripts && npm install
node migrate-to-ssm.js
cd ..

# 3. Build and deploy Lambda + API Gateway
cd lambda
sam build
sam deploy
```

`sam deploy` will show a changeset preview before making any changes. Type `y` to confirm.

After deploy, SAM prints the API Gateway URL. Update `LAMBDA_BASE_URL` in `ghlDataV3.js` if it changes.

### Re-deploying after code changes

```bash
cd lambda
sam build && sam deploy
```

### Deploy updated widgets to S3

After changing any HTML file or `ghlDataV3.js`:

```bash
cd /path/to/widgets

# Upload a single file
aws s3 cp funnel/funnel_widget_indexDB.html s3://mnc-widgets-static/funnel/funnel_widget_indexDB.html --content-type "text/html"

# Or sync an entire folder
aws s3 sync report/ s3://mnc-widgets-static/report/ --exclude "*.php" --content-type "text/html"

# Invalidate CloudFront cache so users get the new version immediately
aws cloudfront create-invalidation \
  --distribution-id EU76IIFKF9NX2 \
  --paths "/*"
```

---

## Updating Widgets on S3

Every time you edit a widget HTML file or `ghlDataV3.js`, you need to upload the new version to S3. CloudFront caches files at the edge — run an invalidation after uploading so users don't see the old cached version.

**Quick deploy script (run from the widgets root):**

```bash
# Upload everything
aws s3 cp ghlDataV3.js s3://mnc-widgets-static/ghlDataV3.js --content-type "application/javascript"
aws s3 sync bar/       s3://mnc-widgets-static/bar/       --exclude "*.php" --content-type "text/html"
aws s3 sync donut/     s3://mnc-widgets-static/donut/     --exclude "*.php" --content-type "text/html"
aws s3 sync funnel/    s3://mnc-widgets-static/funnel/    --exclude "*.php" --content-type "text/html"
aws s3 sync report/    s3://mnc-widgets-static/report/    --exclude "*.php" --content-type "text/html"
aws s3 sync call_chart/ s3://mnc-widgets-static/call_chart/ --exclude "*.php" --exclude "*.py" --content-type "text/html"

# Invalidate CloudFront edge cache
aws cloudfront create-invalidation --distribution-id EU76IIFKF9NX2 --paths "/*"
```

---

## Adding a New Tenant

1. **Add token to SSM:**
   ```bash
   aws ssm put-parameter \
     --name "/ghl-widgets/tenants/NEW_LOCATION_ID/access-token" \
     --value "YOUR_GHL_TOKEN" \
     --type SecureString \
     --region ap-southeast-1
   ```

2. **Add public config to DynamoDB:**
   ```bash
   aws dynamodb put-item \
     --table-name ghl-tenants \
     --region ap-southeast-1 \
     --item '{
       "locationId":     {"S": "NEW_LOCATION_ID"},
       "name":           {"S": "Tenant Name"},
       "customFieldIds": {"M": {
         "sourceCategory": {"S": "fieldId1"},
         "project":        {"S": "fieldId2"},
         "team":           {"S": "fieldId3"}
       }},
       "callClientIds":  {"L": []}
     }'
   ```

3. **Test:**
   ```bash
   curl "https://riedh3l031.execute-api.ap-southeast-1.amazonaws.com/config?locationId=NEW_LOCATION_ID" \
     -H "X-Api-Key: 2d291f00-5145-4a2d-b1d8-ff13fd661f28"
   ```

4. The cache warmer will pick it up automatically on the next 20-minute cycle.

---

## Rotating a GHL Token

When a GHL Bearer token expires or is regenerated:

```bash
aws ssm put-parameter \
  --name "/ghl-widgets/tenants/LOCATION_ID/access-token" \
  --value "NEW_GHL_TOKEN" \
  --type SecureString \
  --overwrite \
  --region ap-southeast-1
```

The Lambda function caches SSM values in-process for 5 minutes — the new token takes effect within 5 minutes automatically. No redeploy needed.

---

## AWS Resources

| Resource | Name / ID |
|---|---|
| S3 bucket | `mnc-widgets-static` |
| CloudFront distribution | `EU76IIFKF9NX2` |
| CloudFront domain | `https://d1u9vpyrlpzvbr.cloudfront.net` |
| API Gateway | `riedh3l031.execute-api.ap-southeast-1.amazonaws.com` |
| CloudFormation stack | `ghl-widgets-backend` |
| DynamoDB table (cache) | `ghl-cache` |
| DynamoDB table (config) | `ghl-tenants` |
| SSM prefix | `/ghl-widgets/` |
| EventBridge rule | `ghl-cache-warmer-schedule` (every 20 min) |
| Region | `ap-southeast-1` (Singapore) |
| IAM deploy user | `ghl-widgets-deploy` |

For a detailed explanation of each AWS service and how it works, see [AWS_GUIDE.md](./AWS_GUIDE.md).

---

## Widget URLs

All widgets follow this URL pattern:

```
https://d1u9vpyrlpzvbr.cloudfront.net/{folder}/{file}.html?locationId={locationId}
```

Example:
```
https://d1u9vpyrlpzvbr.cloudfront.net/funnel/funnel_widget_indexDB.html?locationId=WphrMU0x3Ocd2pEpBJcH
https://d1u9vpyrlpzvbr.cloudfront.net/report/sales_activity_report.html?locationId=WphrMU0x3Ocd2pEpBJcH
https://d1u9vpyrlpzvbr.cloudfront.net/bar/bar_indexedDB.html?locationId=WphrMU0x3Ocd2pEpBJcH
https://d1u9vpyrlpzvbr.cloudfront.net/call_chart/call_chart1.html?locationId=rsdW1sEFWbzzULIapmdQ
```
