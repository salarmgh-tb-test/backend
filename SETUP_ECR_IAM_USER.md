# Setting Up Limited IAM User for ECR in AWS Console

This guide walks you through creating an IAM user with minimal permissions to push/pull Docker images to/from Amazon ECR.

## Step 1: Create IAM User

1. **Sign in to AWS Console**
   - Go to https://console.aws.amazon.com/
   - Navigate to **IAM** (Identity and Access Management)

2. **Create New User**
   - Click **Users** in the left sidebar
   - Click **Create user** button
   - Enter username: `github-actions-ecr` (or any name you prefer)
   - Click **Next**

3. **Set Permissions**
   - Select **Attach policies directly**
   - We'll create a custom policy in the next step, so skip this for now
   - Click **Next**

4. **Review and Create**
   - Review the user details
   - Click **Create user**

## Step 2: Create Custom IAM Policy for ECR

1. **Create Policy**
   - In IAM, click **Policies** in the left sidebar
   - Click **Create policy** button
   - Click the **JSON** tab

2. **Paste the Following Policy** (replace `YOUR_ACCOUNT_ID` and `YOUR_REGION` if needed):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ECRAuthentication",
      "Effect": "Allow",
      "Action": [
        "ecr:GetAuthorizationToken"
      ],
      "Resource": "*"
    },
    {
      "Sid": "ECRRepositoryAccess",
      "Effect": "Allow",
      "Action": [
        "ecr:BatchCheckLayerAvailability",
        "ecr:GetDownloadUrlForLayer",
        "ecr:BatchGetImage",
        "ecr:PutImage",
        "ecr:InitiateLayerUpload",
        "ecr:UploadLayerPart",
        "ecr:CompleteLayerUpload"
      ],
      "Resource": "arn:aws:ecr:*:*:repository/backend"
    },
    {
      "Sid": "ECRDescribeRepositories",
      "Effect": "Allow",
      "Action": [
        "ecr:DescribeRepositories",
        "ecr:DescribeImages"
      ],
      "Resource": "arn:aws:ecr:*:*:repository/backend"
    },
    {
      "Sid": "ECRCreateRepository",
      "Effect": "Allow",
      "Action": [
        "ecr:CreateRepository"
      ],
      "Resource": "arn:aws:ecr:*:*:repository/backend",
      "Condition": {
        "StringEquals": {
          "ecr:ResourceTag/ManagedBy": "github-actions"
        }
      }
    },
    {
      "Sid": "GetCallerIdentity",
      "Effect": "Allow",
      "Action": [
        "sts:GetCallerIdentity"
      ],
      "Resource": "*"
    }
  ]
}
```

**Note:**
- Replace `backend` with your actual ECR repository name if different
- The policy allows:
  - Getting ECR authentication token (required for docker login)
  - Pushing/pulling images to/from the `backend` repository
  - Describing repositories and images
  - Creating the repository if it doesn't exist (for the workflow step)
  - Getting caller identity (used by the workflow to get AWS account ID)

3. **Name the Policy**
   - Click **Next**
   - Policy name: `GitHubActionsECRAccess` (or any name you prefer)
   - Description: `Allows GitHub Actions to push/pull images to ECR backend repository`
   - Click **Create policy**

## Step 3: Attach Policy to User

1. **Go Back to Users**
   - Click **Users** in the left sidebar
   - Click on the user you created (`github-actions-ecr`)

2. **Add Permissions**
   - Click **Add permissions** button
   - Select **Attach policies directly**
   - Search for your policy name (`GitHubActionsECRAccess`)
   - Check the box next to your policy
   - Click **Next**
   - Click **Add permissions**

## Step 4: Create Access Keys

1. **Open User Details**
   - Make sure you're on the user's page (`github-actions-ecr`)

2. **Create Access Key**
   - Click the **Security credentials** tab
   - Scroll down to **Access keys** section
   - Click **Create access key** button

3. **Select Use Case**
   - Select **Application running outside AWS**
   - Check the confirmation checkbox
   - Click **Next**

4. **Add Description (Optional)**
   - Add a description like: `GitHub Actions ECR access`
   - Click **Create access key**

5. **Save Credentials**
   - ⚠️ **IMPORTANT**: You'll see the Access Key ID and Secret Access Key
   - **You can only view the Secret Access Key once!**
   - Click **Download .csv file** or copy both values
   - Save them securely (you'll need them for GitHub secrets)

## Step 5: Add Credentials to GitHub Secrets

1. **Go to GitHub Repository**
   - Navigate to your repository on GitHub
   - Click **Settings** tab
   - Click **Secrets and variables** → **Actions** in the left sidebar

2. **Add Secrets**
   - Click **New repository secret** button

   **Add First Secret:**
   - Name: `AWS_ACCESS_KEY_ID`
   - Value: Paste your Access Key ID (starts with `AKIA...`)
   - Click **Add secret**

   **Add Second Secret:**
   - Click **New repository secret** button again
   - Name: `AWS_SECRET_ACCESS_KEY`
   - Value: Paste your Secret Access Key
   - Click **Add secret**

## Step 6: Verify Setup

Your GitHub Actions workflow should now be able to:
- Authenticate with AWS
- Get ECR login token
- Push Docker images to ECR
- Pull Docker images from ECR (if needed)

## Alternative: More Restrictive Policy (Single Repository Only)

If you want to be even more restrictive and only allow access to a specific repository in a specific region:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ECRAuthentication",
      "Effect": "Allow",
      "Action": [
        "ecr:GetAuthorizationToken"
      ],
      "Resource": "*"
    },
    {
      "Sid": "ECRRepositoryAccess",
      "Effect": "Allow",
      "Action": [
        "ecr:BatchCheckLayerAvailability",
        "ecr:GetDownloadUrlForLayer",
        "ecr:BatchGetImage",
        "ecr:PutImage",
        "ecr:InitiateLayerUpload",
        "ecr:UploadLayerPart",
        "ecr:CompleteLayerUpload",
        "ecr:DescribeRepositories",
        "ecr:DescribeImages",
        "ecr:CreateRepository"
      ],
      "Resource": "arn:aws:ecr:eu-north-1:*:repository/backend"
    },
    {
      "Sid": "GetCallerIdentity",
      "Effect": "Allow",
      "Action": [
        "sts:GetCallerIdentity"
      ],
      "Resource": "*"
    }
  ]
}
```

Replace `eu-north-1` with your actual AWS region if different.

## Troubleshooting

### If you get "Access Denied" errors:
1. Verify the IAM policy is attached to the user
2. Check that the repository name in the policy matches your ECR repository name
3. Ensure the region in the policy matches your AWS region
4. Wait a few minutes after creating the policy (IAM changes can take time to propagate)

### If the workflow can't create the repository:
- The policy includes `ecr:CreateRepository` permission
- Make sure the repository name matches exactly
- Check that you haven't hit ECR repository limits

