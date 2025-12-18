# Backend Service - Kubernetes Deployment

## Overview

The backend service is a Laravel application deployed to Kubernetes using Helm charts. It follows production-grade best practices for security, scalability, and observability.

## Architecture

**Architecture**: Multi-container pod with PHP-FPM and Nginx

**Container Responsibilities**:

-   **PHP-FPM**: Executes Laravel application logic
-   **Nginx**: Reverse proxy, serves static assets, handles HTTP requests

## Key Features

-   Termination grace period (60s) for in-flight requests
-   Database connection pooling
-   Observability dependencies (Prometheus Operator) managed via Helm chart dependencies

## Dependencies & Observability

-   **Prometheus Operator**: Backend monitoring and metrics collection dependencies are handled by the Helm chart through the Prometheus Operator dependency. ServiceMonitor resources are automatically created to expose application metrics to Prometheus, eliminating the need for manual configuration
-   **Service Discovery**: The Helm chart integrates with Prometheus Operator to automatically configure metric scraping, ensuring observability is built into the deployment

## Container Image

-   **Lightweight**: Dockerfile uses Alpine Linux base image, resulting in minimal image size and reduced attack surface
-   **Cache-Friendly**: Multi-stage builds and layer ordering optimize Docker layer caching, reducing build times and bandwidth usage during deployments
-   **Security**: Minimal base image reduces vulnerabilities, and security best practices (non-root user, minimal packages) are enforced at the image level

## User ID Rationale

-   **UID 33 (www-data)**: Standard user for PHP-FPM in Alpine Linux containers
-   **UID 101 (nginx)**: Standard user for Nginx in Alpine Linux containers
-   Matching container user IDs ensures proper file permissions and security

## Resource Allocation

| Component         | CPU Request | CPU Limit | Memory Request | Memory Limit |
| ----------------- | ----------- | --------- | -------------- | ------------ |
| Backend (PHP-FPM) | 250m        | 1000m     | 256Mi          | 512Mi        |
| Backend (Nginx)   | 100m        | 500m      | 128Mi          | 256Mi        |

**QoS Class**: Burstable (requests ≠ limits) - provides flexibility with resource protection, allowing pods to burst beyond requests when capacity is available.

## Helm-Based Deployment

**Decision**: Everything is packaged and deployed using Helm charts

**Rationale**:

-   **Unified Deployment**: All Kubernetes resources (Deployments, Services, ConfigMaps, Secrets, NetworkPolicies, HPA, PDB) are defined and managed through Helm charts, ensuring consistency across environments
-   **Dependency Management**: External dependencies like Prometheus Operator are managed as Helm chart dependencies, providing version control and automated installation
-   **Environment-Specific Configuration**: Helm values files enable easy customization for different environments (dev, staging, production) without code duplication
-   **Versioning & Rollback**: Helm tracks release versions, enabling easy rollback to previous configurations
-   **Template Reusability**: Helm templates reduce duplication and ensure consistent resource definitions across components
-   **CI/CD Integration**: Helm charts integrate seamlessly with CI/CD pipelines, enabling automated deployments
-   **Infrastructure as Code**: All infrastructure configuration is version-controlled and declarative, following GitOps principles

## Deployment Strategy

**Strategy**: Rolling Update with `maxSurge: 1` and `maxUnavailable: 0`

**Benefits**:

-   Zero-downtime deployments
-   New pods are created before old ones are terminated
-   One pod at a time ensures service stability
-   Automatic rollback on failure

## Horizontal Pod Autoscaling

**Configuration**: HPA based on CPU (70%) and memory (80%) utilization

**Scaling Range**: 2-10 replicas

**Scaling Behavior**:

-   **Scale Up**: Aggressive (2 pods per 60s) to handle traffic spikes
-   **Scale Down**: Conservative (25% reduction per 60s) to prevent thrashing

**Trade-off**: Slower scale-down prevents premature pod termination but may result in over-provisioning during traffic drops.

## Health Checks

**Implementation**: Both liveness and readiness probes

-   **Liveness Probe**: Detects deadlocked containers and triggers restart
-   **Readiness Probe**: Ensures traffic only routes to healthy pods
-   **Different Timings**: Readiness checks more frequently to respond quickly to health changes

## PodDisruptionBudget

**Configuration**: `minAvailable: 1` for stateless services

**Benefits**:

-   Protects against node maintenance, cluster upgrades
-   Ensures at least one pod remains available
-   Maintains service continuity during voluntary disruptions

## Network Security

**Model**: Zero-trust networking with NetworkPolicies

**Backend Policy**: Allows ingress from ingress controller, egress to PostgreSQL and DNS

**Benefits**:

-   Limits lateral movement in case of compromise
-   Reduces attack surface
-   Enforces least-privilege network access

## Database Migration Strategy

**Implementation**: Kubernetes Jobs for migrations and seeding, configured as Helm post-install and post-upgrade hooks

**Process**:

1. Run migration job before deployment
2. Verify migration success
3. Deploy new application version
4. Monitor for issues

**Environment-Specific Usage**:

-   **Development/Staging**: Migration and seed jobs are enabled via Helm hooks (post-install and post-upgrade) to automate database setup and updates
-   **Production**: Migration and seed jobs should be disabled and run manually or through a separate CI/CD pipeline. This provides better control, auditability, and reduces the risk of automatic migrations affecting production data
-   The `enabled` flag allows easy toggling of migration jobs per environment

## Security Architecture

### Container Security Context

**Per-container hardening**:

-   `runAsNonRoot: true` - Prevents privilege escalation
-   `allowPrivilegeEscalation: false` - Blocks privilege escalation
-   `capabilities.drop: ALL` - Removes all Linux capabilities

### Service Accounts

**Configuration**: Service account with `automountServiceAccountToken: false`

**Benefits**:

-   Reduces attack surface
-   Prevents unauthorized API server access
-   Follows least-privilege principle

## Monitoring & Observability

**Stack**:

-   **Metrics**: Prometheus + Grafana
-   **Logging**: ELK Stack or Loki

**Key Metrics to Monitor**:

-   Pod CPU/Memory utilization
-   Request latency (p50, p95, p99)
-   Error rates
-   Database connection pool usage
-   HPA scaling events

## Logging Strategy

**Implementation**: Structured logging to stdout/stderr

## Deployment Commands

### Standard Rolling Deployment

1. Update image tag in Helm values
2. Deploy using Helm: `helm upgrade --install backend ./helm -f values-prod.yaml`
3. Monitor rollout: `kubectl rollout status deployment/backend`
4. Verify health: Check pod logs and metrics
5. Rollback if needed: `kubectl rollout undo deployment/backend`

## Configuration Management

### ConfigMaps

Externalize all non-sensitive configuration in ConfigMaps:

-   Environment-specific configuration without image rebuilds
-   Version control of configuration
-   Easy rollback of configuration changes

### Secrets

Store sensitive data like passwords separately in secrets. Kubernetes Secrets with base64 encoding used in CI/CD.

**Future Enhancement**: Integrate with AWS Secrets Manager or HashiCorp Vault
