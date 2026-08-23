# AGENTS.md — VR-GeoNusa

## Project

VR-GeoNusa backend and Machine Learning repository.

Current implementation scope:

* Borobudur only.
* Backend API.
* Research data flow.
* Machine Learning integration.
* No frontend implementation.
* No Prambanan implementation.

Read `docs/PRD.md` and `docs/IMPLEMENTATION_PLAN.md` before proposing significant architectural changes.

---

## Working Method

Do not implement large portions of the product in one task.

For every non-trivial task:

1. Inspect relevant existing code.
2. State the implementation plan.
3. Identify files expected to change.
4. Implement only the requested scope.
5. Run relevant tests.
6. Review the resulting diff.
7. Report:

   * what changed,
   * tests executed,
   * unresolved risks,
   * suggested next task.

Do not silently expand scope.

---

## Architecture Rules

The repository contains two runtime applications:

```text
apps/api
services/ml
```

### Laravel API

Laravel is the application's source of truth.

Laravel owns:

* authentication,
* authorization,
* business logic,
* PostgreSQL persistence,
* learning session state,
* assessment scoring,
* research data,
* heritage content,
* ML inference persistence.

### ML Service

The ML service owns:

* image preprocessing,
* model loading,
* inference,
* prediction formatting.

The ML service must NOT:

* authenticate students,
* access application PostgreSQL directly,
* calculate learning progress,
* calculate research scores,
* manage heritage content.

---

## API Contract

`contracts/openapi.yaml` is the source of truth for the frontend/backend interface.

When an endpoint is added or its request/response changes:

* update the OpenAPI contract in the same change,
* keep backward compatibility unless the task explicitly allows a breaking change.

Never expose internal implementation fields unnecessarily.

Never expose correct answers for pretest or posttest.

---

## Backend Code Rules

Controllers must remain thin.

Prefer:

```text
Request
→ Form Request validation
→ Action / Service
→ Domain logic
→ Repository / Eloquent
→ Resource response
```

Avoid putting complex business logic inside controllers.

Do not create abstractions without a current use case.

Do not create microservices for application domains.

Use a modular monolith.

---

## Session Rules

Learning session transitions are controlled by backend logic.

Valid high-level flow:

```text
pretest
→ exploration
→ posttest
→ self_efficacy
→ completed
```

Never allow clients to directly set session phase.

Every session-scoped mutation must verify:

1. authenticated user,
2. session ownership,
3. valid session write token,
4. allowed current session state,
5. request validity.

---

## Assessment Rules

Pretest, posttest, self-efficacy, and exploration quizzes are separate concepts.

### Pretest/Posttest

Client sends only:

* item ID,
* selected option ID.

Server calculates correctness and score.

Do not return answer correctness during pretest/posttest.

### Micro Quiz

Server still calculates correctness.

Immediate learning feedback may be returned after answer submission.

### Self-Efficacy

Self-efficacy items do not have correct answers.

---

## Research Data Rules

Personally identifying student information must remain separated from research outcomes.

Use `research_participant_id` / `respondent_code` for research records.

Do not duplicate:

* student name,
* student number,

inside assessment result records.

---

## Heritage Rules

A heritage object is not identical to a mathematical geometry shape.

Represent:

```text
HeritageObject
→ HeritageGeometryMapping
→ GeometryShape
```

Use approximation semantics such as:

```text
"didekati sebagai"
```

Do not encode cultural object names directly as geometry classes.

---

## Panorama Rules

Panorama navigation is modeled as a graph.

Use:

```text
PanoramaNode
PanoramaLink
```

Do not implement navigation using only hardcoded `next` / `previous` relationships.

Panorama binary assets must not be stored in PostgreSQL.

---

## Machine Learning Rules

Frontend never calls ML service directly.

Required flow:

```text
Frontend
→ Laravel
→ ML Service
→ Laravel
→ Frontend
```

ML service must expose inference through an adapter around reusable detector code.

Required separation:

```text
preprocessing
detector
inference API
training
evaluation
```

Do not put training code inside FastAPI route handlers.

Do not require FastAPI to use detector classes in tests.

---

## ML Inference Persistence

For every successful inference, Laravel must be able to record:

* learning session,
* panorama node,
* camera yaw,
* camera pitch,
* camera FOV,
* model version,
* inference latency,
* total latency,
* detections,
* confidence.

A prediction must always be attributable to a model version.

---

## Dataset Rules

Training annotations are object-level bounding boxes.

Do not treat a complete panorama as one geometry label.

Training, validation, and test data must be explicitly separated.

Avoid obvious data leakage between near-identical crops.

---

## Testing

### Backend

Run relevant Laravel tests after backend modifications.

Critical authorization and state-machine behavior requires feature tests.

### ML

Run `pytest` after Python ML modifications.

Preprocessing and inference interfaces require unit tests.

Do not declare a task complete if relevant tests fail.

---

## Quality Gates

Before completing a meaningful task:

### Backend

Run:

```bash
php artisan test
```

and project lint/static-analysis commands if configured.

### ML

Run:

```bash
pytest
```

and configured lint/type checks.

Do not suppress failing tests merely to make CI green.

---

## Database

Use migrations for schema changes.

Never manually rely on a developer's existing local database state.

Fresh setup must work using:

```text
fresh database
→ migrations
→ required seeders
→ tests/application
```

Seeders may provide development/demo data but must not be required for production correctness.

---

## Dependencies

Do not add new production dependencies unless they provide clear value.

Before adding a dependency:

1. check whether framework/native functionality already solves the problem,
2. explain why the dependency is needed.

---

## Security

Never commit:

* passwords,
* tokens,
* secret keys,
* real student credentials,
* production database credentials.

Use environment variables.

Do not log raw authentication/session tokens.

Do not expose stack traces through production API responses.

---

## Scope Guardrails

Do NOT implement unless explicitly requested:

* React/frontend UI,
* Prambanan,
* 3D object interaction,
* teacher dashboard,
* gamification,
* certificates,
* mobile application,
* Kubernetes,
* microservice decomposition,
* continuous real-time inference.

---

## Code Review Rules

Flag changes that:

* allow session-state skipping,
* calculate research scores on the client,
* expose correct pre/posttest answers,
* access another student's learning session,
* mix student identity directly into research result tables,
* allow ML service direct database access,
* lose model-version attribution,
* hardcode panorama navigation,
* introduce credentials or secrets,
* modify API behavior without updating OpenAPI.

---

## Completion Format

At the end of each implementation task, report:

```text
Implemented:
- ...

Tests:
- ...

Files changed:
- ...

Risks / follow-up:
- ...
```

If requirements are ambiguous in a way that changes architecture or research-data semantics, stop and ask instead of guessing.
