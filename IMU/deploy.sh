#!/bin/bash
# =============================================================================
# deploy.sh — Build, push, and run the GHL migration on Cloud Run Jobs
#
# BEFORE RUNNING:
#   1. Copy .env.example to .env and fill in all values
#   2. Fill in the two placeholders below:
#
#   YOUR_PROJECT_ID  — your GCP project ID (e.g. my-gcp-project)
#   YOUR_REGION      — deployment region (default below: asia-southeast1)
#
# The .env file is baked into the Docker image at build time.
# No env vars are passed via gcloud — all config lives in .env.
# =============================================================================

# Step 1: Set project and region variables
export PROJECT_ID="YOUR_PROJECT_ID"
export REGION="asia-southeast1"

# Step 2: Set the active gcloud project
gcloud config set project $PROJECT_ID

# Step 3: Enable required GCP APIs
gcloud services enable run.googleapis.com containerregistry.googleapis.com

# Step 4: Authenticate Docker with Google Container Registry
gcloud auth configure-docker

# Step 5: Build the Docker image (bakes .env into the image)
docker build -t gcr.io/$PROJECT_ID/ghl-migration:latest .

# Step 6: Push the image to GCR
docker push gcr.io/$PROJECT_ID/ghl-migration:latest

# Step 7: Create the Cloud Run Job
# Run this once. No --set-env-vars needed — config is baked into the image via .env.
gcloud run jobs create ghl-migration \
  --image gcr.io/$PROJECT_ID/ghl-migration:latest \
  --region $REGION \
  --memory 1Gi \
  --cpu 1 \
  --task-timeout 86400 \
  --max-retries 0

# Step 8: Rebuild and redeploy after changing .env values
# Edit .env, then run these three commands:
docker build -t gcr.io/$PROJECT_ID/ghl-migration:latest .
docker push gcr.io/$PROJECT_ID/ghl-migration:latest
gcloud run jobs update ghl-migration \
  --image gcr.io/$PROJECT_ID/ghl-migration:latest \
  --region $REGION

# Step 9: Execute the job (triggers one run)
# Phase 1 — sync messages on or before MESSAGE_CUTOFF_DATE
gcloud run jobs execute ghl-migration --region $REGION

# Phase 2 — sync messages after MESSAGE_CUTOFF_DATE (run after Phase 1 completes)
# Edit .env to set PHASE=2, rebuild, update, then:
# gcloud run jobs execute ghl-migration --region $REGION

# Step 10: Stream live logs for the latest execution
# Replace EXECUTION_NAME with the execution ID printed by step 9 (e.g. ghl-migration-xxxxx)
gcloud run jobs executions logs tail EXECUTION_NAME --region $REGION

# Step 11: Query only ERROR lines from Cloud Logging
gcloud logging read \
  "resource.type=cloud_run_job AND resource.labels.job_name=ghl-migration AND textPayload:ERROR" \
  --project $PROJECT_ID \
  --limit 200 \
  --format "table(timestamp,textPayload)"
