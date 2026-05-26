# Dashboard Migration Prompt — GHL to AWS Lambda

---

## Prompt

I have widgets/dashboards with the following folders: `bar`, `call chart`, `crm-tracker`, `donut`, `funnel`, and `report`.

**Please review ALL files across these folders thoroughly before giving me any plan.**

---

### Current Situation

- All folders share a common data-fetching file called `ghlDataV2.js` that calls the GHL (GoHighLevel) API directly from the frontend
- I am an admin managing many subaccounts, each with large volumes of data
- Every page load triggers multiple direct API calls, causing very slow load times
- I am currently caching data in IndexedDB on the frontend, but this is causing data inconsistency bugs and messy state

---

### Goals

#### 1. Migrate API calls to AWS Lambda
- Move all data-fetching logic from `ghlDataV2.js` into AWS Lambda functions
- The frontend should call Lambda endpoints instead of hitting the GHL API directly
- Structure the Lambda functions cleanly — one per data domain or subaccount type if appropriate

#### 2. Reduce load times significantly
- Since I load data across many subaccounts and many data types simultaneously, propose a smart caching and/or queuing strategy at the Lambda or storage layer
- Repeated loads for the same subaccount/date range should return cached data instantly without hitting the GHL API again
- Suggest the best caching layer for this use case (e.g. DynamoDB TTL, ElastiCache/Redis, S3, or a combination)

#### 3. Replace or fix IndexedDB
- Review how IndexedDB is currently being used across all modules
- Recommend a better approach that eliminates inconsistency — either remove IndexedDB entirely in favour of server-side caching, or redesign the IndexedDB layer with a clean, reliable strategy
- The solution should handle stale data gracefully

#### 4. Preserve the existing UI
- Restructure the data-fetching and caching layer only
- Keep the visual design of all 6 dashboard modules (`bar`, `call chart`, `crm-tracker`, `donut`, `funnel`, `report`) as close to the original as possible
- Minimise changes to rendering logic

#### 5. Step-by-step Lambda setup guide
After reviewing the files and presenting the migration plan, walk me through the full setup:
- How to set up AWS Lambda from scratch for this use case
- How to structure and name the Lambda functions
- How to connect the frontend to Lambda (API Gateway or Function URLs)
- How to handle authentication, CORS, and environment variables securely
- How to store the GHL API keys/tokens safely (AWS Secrets Manager or Parameter Store)
- How to deploy and test locally and in production
- How to set up scheduled cache warming if needed (EventBridge/CloudWatch)

---

### Deliverables Expected

1. **Full file review summary** — what each module does, how it uses `ghlDataV2.js`, and what data it fetches
2. **Current problems identified** — API bottlenecks, IndexedDB issues, duplicated logic
3. **Proposed architecture diagram or description** — how Lambda, caching, and the frontend fit together
4. **Phased migration plan** — broken into clear phases with priorities (what to migrate first, what can wait)
5. **Restructured code plan** — which files to create, delete, or modify across all 6 modules
6. **Step-by-step Lambda setup guide** — from AWS account setup to live deployment

---

> **Note:** Keep the UI changes minimal. The priority is backend restructuring for speed and reliability.
