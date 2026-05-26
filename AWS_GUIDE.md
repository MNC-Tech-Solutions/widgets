# AWS Guide — Your GHL Widget Backend

This document explains every AWS service used in your project:
what it is, what it does, how it works, and how to set it up.

---

## Table of Contents

1. [AWS Lambda](#1-aws-lambda)
2. [API Gateway](#2-api-gateway)
3. [DynamoDB](#3-dynamodb)
4. [SSM Parameter Store](#4-ssm-parameter-store)
5. [EventBridge](#5-eventbridge)
6. [IAM (Roles & Permissions)](#6-iam-roles--permissions)
7. [CloudWatch](#7-cloudwatch)
8. [How They All Connect](#how-they-all-connect)

---

## 1. AWS Lambda

### What is it?
Lambda lets you run code **without managing any server**. You just upload your code, and AWS runs it when needed. You only pay when your code is actually running (not 24/7 like a server).

### What does it do in your project?
You have **4 Lambda functions**:

| Function | What it does |
|---|---|
| `ghl-data-proxy` | Receives requests from your widgets, checks DynamoDB cache, fetches from GHL API if needed, returns data |
| `call-data-proxy` | Fetches call records (CDR data) for your call chart widgets |
| `ghl-config-api` | Returns location config (name, field IDs) — **never returns tokens** |
| `ghl-cache-warmer` | Runs every 20 min automatically, pre-fetches data for all 59 tenants so widgets load fast |

### How does it work?
```
Someone opens your widget
    → Widget calls API Gateway URL
        → API Gateway triggers Lambda function
            → Lambda runs your Node.js code
                → Code checks DynamoDB for cached data
                    → If cached: return it immediately (fast!)
                    → If not cached: call GHL API, save to DynamoDB, return it
```

Lambda functions are **stateless** — each time they run, they start fresh. They don't remember anything between runs (that's what DynamoDB is for).

### How to set it up (AWS Console)

1. Go to [AWS Lambda Console](https://ap-southeast-1.console.aws.amazon.com/lambda/home?region=ap-southeast-1)
2. Click **Create function**
3. Choose **Author from scratch**
4. Set:
   - Function name: e.g. `ghl-data-proxy`
   - Runtime: **Node.js 18.x**
   - Architecture: x86_64
5. Click **Create function**
6. Upload your code (zip file or paste directly)
7. Set **timeout** under Configuration → General config (default is 3s, set to 30s for data proxy, 600s for cache warmer)

> **In your project**: All 4 functions were deployed automatically using **AWS SAM** (`sam deploy`) from the `lambda/` folder. You don't need to create them manually.

### Key settings to know
- **Timeout**: Max time the function can run. Default 3 seconds — too short for GHL API calls.
- **Memory**: More memory = faster code + more CPU. Set to 256MB for proxy functions.
- **Environment variables**: Key-value pairs your code can read (used for table names, etc.)

---

## 2. API Gateway

### What is it?
API Gateway is the **front door** to your Lambda functions. It gives you a public HTTPS URL that your widgets call. It receives the request, routes it to the right Lambda function, and sends back the response.

### What does it do in your project?
It provides the URL your widgets use:
```
https://riedh3l031.execute-api.ap-southeast-1.amazonaws.com
```

And routes each path to the right Lambda:

| Widget calls this URL | API Gateway sends to |
|---|---|
| `GET /config?locationId=...` | `ghl-config-api` Lambda |
| `GET /ghl/pipelines?locationId=...` | `ghl-data-proxy` Lambda |
| `GET /ghl/users?locationId=...` | `ghl-data-proxy` Lambda |
| `GET /ghl/opportunities?locationId=...` | `ghl-data-proxy` Lambda |
| `GET /ghl/conversations?locationId=...` | `ghl-data-proxy` Lambda |
| `POST /ghl/contacts/search?locationId=...` | `ghl-data-proxy` Lambda |
| `GET /ghl/calendars/events?locationId=...` | `ghl-data-proxy` Lambda |
| `GET /ghl/contacts/{id}/notes?locationId=...` | `ghl-data-proxy` Lambda |
| `DELETE /ghl/cache?locationId=...` | `ghl-data-proxy` Lambda (refresh button) |
| `GET /call/cdr?locationId=...` | `call-data-proxy` Lambda |

### How does it work?
```
Widget (browser)
    → HTTPS request to api-gateway-url/ghl/pipelines?locationId=abc
        → API Gateway reads the path (/ghl/pipelines)
            → Finds the matching route
                → Calls ghl-data-proxy Lambda with the request details
                    → Lambda returns response
                        → API Gateway forwards response back to browser
```

It also handles **CORS** — this allows your widget pages (on a different domain) to call the API without the browser blocking it.

### How to set it up (AWS Console)

1. Go to [API Gateway Console](https://ap-southeast-1.console.aws.amazon.com/apigateway/main/apis?region=ap-southeast-1)
2. Click **Create API**
3. Choose **HTTP API** (not REST API — HTTP API is simpler and cheaper)
4. Add integrations: select Lambda functions
5. Configure routes: e.g. `GET /ghl/pipelines` → select `ghl-data-proxy`
6. Configure CORS:
   - Allow origins: `*`
   - Allow headers: `Content-Type, X-Api-Key`
7. Click **Deploy**

> **In your project**: Already deployed via SAM. You can view it in the console under API Gateway → `ghl-widgets-api`.

### Key things to know
- **CORS**: Must be enabled or browsers will block the requests
- **X-Api-Key header**: Your widgets send this with every request. Lambda checks it matches the secret stored in SSM. This prevents random people from using your API.
- **Stages**: API Gateway has a "stage" (like a version). Yours is `$default`.

---

## 3. DynamoDB

### What is it?
DynamoDB is AWS's **database** — specifically a NoSQL database. It stores data as items (like rows) with attributes (like columns), but very flexible — each item can have different attributes.

Unlike a regular database, DynamoDB is **serverless** — you don't manage any database server. It scales automatically and you pay per request.

### What does it do in your project?
Two tables:

**Table 1: `ghl-cache`** — Stores GHL API responses so Lambda doesn't have to call GHL every time.
```
Example item:
  pk:          "rsdW1sEFWbzzULIapmdQ#ghl"    ← location ID + type
  sk:          "pipelines"                    ← what data this is
  data:        "[{id: 'abc', name: 'Sales Pipeline', ...}]"  ← the actual data (JSON)
  fetchedAt:   1748234567890                  ← when it was fetched
  ttl:         1748320967                     ← auto-delete after this time (Unix timestamp)
```

**Table 2: `ghl-tenants`** — Stores public config for each of your 59 locations.
```
Example item:
  locationId:   "rsdW1sEFWbzzULIapmdQ"
  name:         "PV Tenant"
  customFieldIds: { sourceCategory: "abc123", project: "def456", team: "ghi789" }
  callClientIds:  ["client1", "client2"]
```

### How does it work?

**Reading (cache hit)**:
```
Lambda needs pipelines for location X
    → Query DynamoDB: pk="X#ghl", sk="pipelines"
        → Found! Check if ttl hasn't expired
            → Return cached data immediately (milliseconds)
```

**Writing (cache miss)**:
```
Lambda needs pipelines for location X
    → Query DynamoDB → not found (or expired)
        → Call GHL API → get pipelines
            → Save to DynamoDB with TTL = now + 24 hours
                → Return data to widget
```

**TTL (Time To Live)**: DynamoDB automatically deletes items after the `ttl` timestamp passes. This is how the cache expires without you having to manually clean it up.

| Data type | Cache expires after |
|---|---|
| Pipelines | 24 hours |
| Users | 6 hours |
| Opportunities | 20 minutes |
| Conversations / Contacts | 15-20 minutes |
| Call records | 20 minutes |

### How to set it up (AWS Console)

1. Go to [DynamoDB Console](https://ap-southeast-1.console.aws.amazon.com/dynamodb/home?region=ap-southeast-1)
2. Click **Create table**
3. For `ghl-cache`:
   - Table name: `ghl-cache`
   - Partition key: `pk` (String)
   - Sort key: `sk` (String)
   - Billing mode: On-demand
4. After creating, go to **Additional settings** → Enable **TTL** → set TTL attribute name to `ttl`
5. Repeat for `ghl-tenants`:
   - Partition key: `locationId` (String)
   - No sort key needed

> **In your project**: Both tables were created automatically by SAM deploy.

---

## 4. SSM Parameter Store

### What is it?
SSM Parameter Store is AWS's **secure secret storage**. It stores sensitive values (like passwords and API tokens) encrypted, so you don't hardcode them in your code or store them in files that could leak.

### What does it do in your project?
Stores all your GHL Bearer tokens and other credentials — **completely off your website and off GitHub**:

```
/ghl-widgets/tenants/rsdW1sEFWbzzULIapmdQ/access-token  ← PV tenant GHL token
/ghl-widgets/tenants/WphrMU0x3Ocd2pEpBJcH/access-token  ← Demo tenant GHL token
... (59 total tenant tokens)
/ghl-widgets/call/username                               ← Call API username
/ghl-widgets/call/password                               ← Call API password
/ghl-widgets/api-key                                     ← Secret key widgets use to call your API
```

### How does it work?
```
Lambda needs GHL token for location X
    → Call SSM: GetParameter("/ghl-widgets/tenants/X/access-token")
        → SSM decrypts the value using KMS (AWS encryption key)
            → Returns the token
                → Lambda uses it to call GHL API
```

Your Lambda code **caches** the token in memory for 5 minutes so it doesn't call SSM on every single request (SSM has rate limits and small costs per call).

**The browser NEVER sees the token.** It only exists inside Lambda.

### How to set it up (AWS Console)

1. Go to [SSM Parameter Store Console](https://ap-southeast-1.console.aws.amazon.com/systems-manager/parameters?region=ap-southeast-1)
2. Click **Create parameter**
3. Set:
   - Name: `/ghl-widgets/api-key`
   - Tier: Standard (free)
   - Type: **SecureString** ← encrypts the value
   - Value: your secret value
4. Click **Create parameter**

> **In your project**: All 59 tenant tokens were uploaded automatically by running `node scripts/migrate-to-ssm.js`. You can view them in the console — the values show as `****` (encrypted).

### Why not use a .env file or config.json?
- `.env` files can accidentally get committed to GitHub → tokens leaked
- `config.json` was your old setup → 59 live GHL tokens were in your git repo
- SSM: encrypted, access-controlled, never in your code, never in git

---

## 5. EventBridge

### What is it?
EventBridge is AWS's **scheduler and event bus**. You can set up rules that trigger Lambda functions on a schedule (like a cron job) or in response to events from other AWS services.

### What does it do in your project?
Runs your `ghl-cache-warmer` Lambda function **every 20 minutes** automatically:

```
Every 20 minutes:
    EventBridge fires
        → Triggers ghl-cache-warmer Lambda
            → Lambda loops through all 59 tenants
                → Fetches pipelines, users, opportunities for each
                    → Saves to DynamoDB cache
                        → Next time a widget opens, cache is already warm (instant load!)
```

### How does it work?
EventBridge uses a **cron expression** to define the schedule. Yours is:
```
cron(0 */20 * * ? *)  ←  every 20 minutes
```

| Cron part | Meaning |
|---|---|
| `0` | at second 0 |
| `*/20` | every 20 minutes |
| `* * ? *` | every hour, every day, any day of week, every year |

### How to set it up (AWS Console)

1. Go to [EventBridge Console](https://ap-southeast-1.console.aws.amazon.com/events/home?region=ap-southeast-1)
2. Click **Rules** → **Create rule**
3. Set:
   - Name: `ghl-cache-warmer-schedule`
   - Rule type: **Schedule**
4. Schedule pattern: **Cron expression** → `0 */20 * * ? *`
5. Target: **Lambda function** → select `ghl-cache-warmer`
6. Click **Create**

> **In your project**: Created automatically by SAM deploy.

---

## 6. IAM (Roles & Permissions)

### What is it?
IAM (Identity and Access Management) controls **who can access what** in AWS. Every Lambda function has an IAM Role — a set of permissions that defines what AWS services it's allowed to use.

### What does it do in your project?
Your Lambda functions need permission to:
- Read from SSM Parameter Store (to get GHL tokens)
- Read/write DynamoDB (to use the cache)
- Write logs to CloudWatch (so you can debug)

Without these permissions, Lambda would be blocked from calling any of these services.

### How does it work?
```
Lambda function runs
    → Tries to read from SSM
        → AWS checks: does this Lambda's IAM Role allow ssm:GetParameter?
            → Yes → allowed, returns the value
            → No  → AccessDenied error
```

Your Lambda's IAM role has these permissions:
```json
{
  "ssm:GetParameter"    → on /ghl-widgets/* paths only
  "dynamodb:GetItem"    → on ghl-cache and ghl-tenants tables
  "dynamodb:PutItem"
  "dynamodb:DeleteItem"
  "dynamodb:Scan"
  "logs:CreateLogGroup" → for CloudWatch logging
  "logs:CreateLogStream"
  "logs:PutLogEvents"
}
```

### How to set it up (AWS Console)

1. Go to [IAM Console](https://console.aws.amazon.com/iam/home)
2. Click **Roles** → **Create role**
3. Trusted entity: **AWS Service** → **Lambda**
4. Attach policies:
   - `AWSLambdaBasicExecutionRole` (for CloudWatch logs) — managed policy
   - Create a custom inline policy for SSM and DynamoDB access
5. Name the role: e.g. `ghl-lambda-role`
6. Assign this role when creating each Lambda function

> **In your project**: The IAM role was created automatically by SAM deploy using the permissions defined in `lambda/template.yaml`.

---

## 7. CloudWatch

### What is it?
CloudWatch is AWS's **logging and monitoring** service. Every time a Lambda function runs, it automatically writes logs to CloudWatch. You can use this to debug errors and see what's happening.

### What does it do in your project?
Stores logs from all 4 Lambda functions. Every `console.log`, `console.warn`, and `console.error` in your Lambda code appears here.

### How to view logs

1. Go to [CloudWatch Console](https://ap-southeast-1.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-1)
2. Click **Log groups** in the left menu
3. Find `/aws/lambda/ghl-data-proxy` (or any other function name)
4. Click on a **log stream** (each invocation is a stream)
5. Read the logs

### What to look for

```
START RequestId: abc123          ← Lambda started
GHL 429 at /opportunities — retrying in 10s   ← GHL rate limited us
X-Cache: MISS                    ← cache miss, fetched from GHL
X-Cache: HIT                     ← cache hit, served from DynamoDB
END RequestId: abc123            ← Lambda finished
REPORT Duration: 234.56 ms       ← how long it took
```

### Setting up Alarms (optional)
You can set CloudWatch alarms to email you if Lambda errors spike:

1. CloudWatch → **Alarms** → **Create alarm**
2. Select metric: Lambda → Errors → your function name
3. Set threshold: e.g. errors > 5 in 5 minutes
4. Notification: SNS → email

---

## How They All Connect

Here's the complete picture of how all services work together:

```
┌─────────────────────────────────────────────────────────────────┐
│                    YOUR WIDGET (Browser)                        │
│   bar/donut/funnel/report HTML files                           │
│   → calls API Gateway URL with X-Api-Key header                │
└────────────────────────┬────────────────────────────────────────┘
                         │ HTTPS request
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    API GATEWAY                                  │
│   Routes /ghl/pipelines → ghl-data-proxy Lambda               │
│   Routes /config        → ghl-config-api Lambda               │
│   Routes /call/cdr      → call-data-proxy Lambda              │
│   Handles CORS so browser doesn't block requests               │
└────────────────────────┬────────────────────────────────────────┘
                         │ invokes
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    LAMBDA FUNCTIONS                             │
│                                                                 │
│   ghl-data-proxy:    checks DynamoDB cache → GHL API if miss  │
│   ghl-config-api:    reads ghl-tenants DynamoDB table         │
│   call-data-proxy:   fetches call CDR data                    │
│   ghl-cache-warmer:  pre-warms all 59 tenants                 │
│                                                                 │
│   All functions write logs to CloudWatch                        │
└──────────┬─────────────────────────┬───────────────────────────┘
           │ reads secrets           │ reads/writes cache
           ▼                         ▼
┌─────────────────┐       ┌──────────────────────────┐
│  SSM PARAMETER  │       │       DYNAMODB            │
│     STORE       │       │                           │
│                 │       │  ghl-cache table:         │
│  59 GHL tokens  │       │    cached API responses   │
│  Call username  │       │    auto-expires via TTL   │
│  Call password  │       │                           │
│  API key        │       │  ghl-tenants table:       │
│                 │       │    location config        │
│  Encrypted      │       │    (no tokens!)           │
└─────────────────┘       └──────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    EVENTBRIDGE                                  │
│   Runs every 20 minutes                                        │
│   → Triggers ghl-cache-warmer Lambda                          │
│   → Cache warmer pre-fills DynamoDB for all 59 tenants        │
│   → Widgets load instantly because data is already cached      │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    IAM                                          │
│   Lambda role permissions:                                     │
│     ssm:GetParameter  → can read SSM secrets                  │
│     dynamodb:GetItem  → can read DynamoDB                     │
│     dynamodb:PutItem  → can write DynamoDB                    │
│     logs:PutLogEvents → can write CloudWatch logs             │
└─────────────────────────────────────────────────────────────────┘
```

---

## Cost Estimate (Monthly)

| Service | Usage | Est. Cost |
|---|---|---|
| Lambda | ~2M requests/month | ~$0.40 |
| API Gateway | ~2M requests/month | ~$2.00 |
| DynamoDB | On-demand, light usage | ~$1.00 |
| SSM Parameter Store | Standard tier, ~65 params | **Free** |
| EventBridge | 1 rule, triggers every 20 min | **Free** |
| CloudWatch | Basic log storage | ~$0.50 |
| **Total** | | **~$4/month** |

> Much cheaper than GoDaddy hosting, and your GHL tokens are now completely secure.

---

## Quick Reference — AWS Console Links (ap-southeast-1)

| Service | Console Link |
|---|---|
| Lambda | https://ap-southeast-1.console.aws.amazon.com/lambda |
| API Gateway | https://ap-southeast-1.console.aws.amazon.com/apigateway |
| DynamoDB | https://ap-southeast-1.console.aws.amazon.com/dynamodb |
| SSM Parameter Store | https://ap-southeast-1.console.aws.amazon.com/systems-manager/parameters |
| EventBridge | https://ap-southeast-1.console.aws.amazon.com/events |
| CloudWatch Logs | https://ap-southeast-1.console.aws.amazon.com/cloudwatch/home#logsV2:log-groups |
| IAM Roles | https://console.aws.amazon.com/iam/home#/roles |
