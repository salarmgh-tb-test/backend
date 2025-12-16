# CI/CD Pipeline for AWS EKS

This pipeline fulfills **Task B3** requirements:
- ✅ Builds Docker images
- ✅ Runs tests (PHPUnit, linting, security audit)
- ✅ Pushes to ECR
- ✅ Deploys to EKS
- ✅ Uses IaC (Helm charts with environment-specific values)
- ✅ Environment promotion (dev → staging → prod)
- ✅ Protected environments with approval gates

## Pipeline Flow

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    Test     │────▶│    Lint &   │────▶│    Build    │
│  (PHPUnit)  │     │  Security   │     │   (Docker)  │
└─────────────┘     └─────────────┘     └─────────────┘
                                               │
                    ┌──────────────────────────┘
                    ▼
            ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
            │  Deploy to  │────▶│  Deploy to  │────▶│  Deploy to  │
            │     DEV     │     │   STAGING   │     │ PRODUCTION  │
            │ (automatic) │     │ (automatic) │     │ (approval)  │
            └─────────────┘     └─────────────┘     └─────────────┘
```

## Files

| File | Purpose |
|------|---------|
| `ci-cd.yml` | Full CI/CD pipeline with environment promotion |
| `deploy.yml` | Simple single-environment deployment (legacy) |

## Branch Strategy

| Branch | Deploys To |
|--------|-----------|
| `develop` | dev |
| `main`/`master` | dev → staging → prod |
| Pull Request | Tests only (no deployment) |

## Required GitHub Secrets

### Core Secrets (Required)

| Secret | Description |
|--------|-------------|
| `AWS_ROLE_ARN` | IAM role ARN for GitHub Actions (OIDC) - used for build |
| `AWS_ROLE_ARN_DEV` | IAM role ARN for dev environment |
| `AWS_ROLE_ARN_STAGING` | IAM role ARN for staging environment |
| `AWS_ROLE_ARN_PROD` | IAM role ARN for production environment |
| `EKS_CLUSTER_NAME_DEV` | Name of dev EKS cluster |
| `EKS_CLUSTER_NAME_STAGING` | Name of staging EKS cluster |
| `EKS_CLUSTER_NAME_PROD` | Name of production EKS cluster |

### Optional Secrets

| Secret | Default | Description |
|--------|---------|-------------|
| `AWS_REGION` | `us-east-1` | AWS region |
| `ECR_REPOSITORY` | `backend` | ECR repository name |

## GitHub Environments Setup

Create these environments in GitHub (`Settings` → `Environments`):

### 1. `dev` Environment
- No protection rules
- Auto-deploy from `develop` branch

### 2. `staging` Environment
- Required reviewers: 0 (optional)
- Wait timer: 0 minutes
- Deployment branches: `main`, `master`

### 3. `production` Environment (Protected)
- **Required reviewers**: Add 1-2 team leads
- **Wait timer**: 5 minutes (optional cool-down)
- **Deployment branches**: `main`, `master` only
- Enable "Prevent self-review" if desired

## AWS IAM Setup

### 1. Create OIDC Identity Provider

```bash
# Create the OIDC provider (one-time setup)
aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com \
  --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1
```

### 2. Create IAM Roles

Create separate roles for each environment with appropriate permissions:

**Trust Policy (adjust repo name):**

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Federated": "arn:aws:iam::ACCOUNT_ID:oidc-provider/token.actions.githubusercontent.com"
      },
      "Action": "sts:AssumeRoleWithWebIdentity",
      "Condition": {
        "StringEquals": {
          "token.actions.githubusercontent.com:aud": "sts.amazonaws.com"
        },
        "StringLike": {
          "token.actions.githubusercontent.com:sub": "repo:YOUR_ORG/YOUR_REPO:environment:ENVIRONMENT_NAME"
        }
      }
    }
  ]
}
```

**Required IAM Permissions:**

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ECRPermissions",
      "Effect": "Allow",
      "Action": [
        "ecr:GetAuthorizationToken",
        "ecr:BatchCheckLayerAvailability",
        "ecr:GetDownloadUrlForLayer",
        "ecr:BatchGetImage",
        "ecr:PutImage",
        "ecr:InitiateLayerUpload",
        "ecr:UploadLayerPart",
        "ecr:CompleteLayerUpload",
        "ecr:DescribeRepositories",
        "ecr:CreateRepository"
      ],
      "Resource": "*"
    },
    {
      "Sid": "EKSPermissions",
      "Effect": "Allow",
      "Action": [
        "eks:DescribeCluster",
        "eks:ListClusters"
      ],
      "Resource": "*"
    },
    {
      "Sid": "STSPermissions",
      "Effect": "Allow",
      "Action": [
        "sts:GetCallerIdentity"
      ],
      "Resource": "*"
    }
  ]
}
```

### 3. Update EKS aws-auth ConfigMap

Add the IAM role to your EKS cluster's `aws-auth` ConfigMap:

```bash
kubectl edit configmap aws-auth -n kube-system
```

Add under `mapRoles`:

```yaml
- rolearn: arn:aws:iam::ACCOUNT_ID:role/GitHubActions-Deploy-Dev
  username: github-actions-dev
  groups:
    - system:masters  # Or create a custom RBAC role
```

## Helm Values per Environment

The pipeline uses environment-specific values files:

| Environment | Values File | Key Differences |
|-------------|-------------|-----------------|
| dev | `values-dev.yaml` | 1 replica, debug logging, no autoscaling |
| staging | `values-staging.yaml` | 2 replicas, info logging, basic autoscaling |
| production | `values-prod.yaml` | 3+ replicas, minimal logging, aggressive autoscaling, security headers |

## Manual Deployment

Trigger a manual deployment via GitHub Actions UI:

1. Go to `Actions` → `CI/CD Pipeline`
2. Click `Run workflow`
3. Select:
   - **Image tag**: Custom tag or leave empty for commit SHA
   - **Deploy environment**: `dev`, `staging`, or `prod`

## Pipeline Jobs

### 1. Test Job
- Runs PHPUnit with PostgreSQL
- Generates code coverage report
- Uploads coverage artifact

### 2. Lint & Security Job
- PHP CodeSniffer (PSR-12)
- PHPStan static analysis
- Composer security audit

### 3. Build Job
- Builds PHP-FPM and Nginx images
- Pushes to ECR with tags
- Runs Trivy vulnerability scan
- Uses Docker layer caching

### 4-6. Deploy Jobs
- Sequential deployment: dev → staging → prod
- Uses Helm with environment values
- Runs smoke/health tests
- Creates deployment tags (prod only)

## Troubleshooting

### Tests Failing
```bash
# Run tests locally
composer install
php artisan key:generate
./vendor/bin/phpunit
```

### ECR Push Fails
- Check IAM role permissions
- Verify OIDC provider is configured
- Ensure ECR repository exists

### EKS Connection Issues
- Verify cluster name in secrets
- Check IAM role has EKS access
- Ensure aws-auth ConfigMap is updated

### Deployment Stuck
```bash
# Check pod status
kubectl get pods -n backend-{env} -l app.kubernetes.io/name=backend

# Check events
kubectl describe deployment backend -n backend-{env}

# Check logs
kubectl logs -n backend-{env} -l app.kubernetes.io/name=backend --tail=100
```

## Security Considerations

1. **OIDC Authentication**: No long-lived credentials stored
2. **Separate IAM Roles**: Each environment has its own role with least-privilege
3. **Protected Environments**: Production requires approval
4. **Image Scanning**: Trivy scans for vulnerabilities
5. **Security Audit**: Composer audit checks for known vulnerabilities
6. **Production Headers**: Security headers added via Nginx config
