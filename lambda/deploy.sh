#!/bin/bash
# Deploys all Lambda functions to AWS using SAM CLI.
# Prerequisites: aws cli configured, sam cli installed (pip install aws-sam-cli)
#
# Usage: bash lambda/deploy.sh [--guided]
#   --guided  Run interactive setup on first deploy (sets stack name, region, S3 bucket)
#   (no flag) Fast re-deploy using saved samconfig.toml settings

set -e

cd "$(dirname "$0")"

# Build the shared layer dependencies
echo "==> Building shared layer..."
npm install --prefix layer/nodejs --omit=dev

# Build + deploy via SAM
if [ "$1" = "--guided" ]; then
  sam build && sam deploy --guided
else
  sam build && sam deploy
fi

echo ""
echo "==> Deploy complete. API URL is shown in the Outputs above."
