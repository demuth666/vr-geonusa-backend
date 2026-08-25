# VR-GeoNusa — Codex Implementation Plan

## Principle

Build one verified vertical slice at a time.

Student REST API and Filament must reuse the same application/domain logic.

Never prompt Codex with:

> Build the whole VR-GeoNusa backend.

Instead use:

```text
plan
→ inspect
→ implement
→ test
→ review
→ commit
→ next slice
```

---

# Phase 0 — Repository Foundation

## Goal

A fresh clone can boot backend dependencies and both services have a clear boundary.

## Deliverables

```text
apps/api
services/ml
contracts
docs
infrastructure
AGENTS.md
docker-compose.yml
.env.example
README.md
```

## Infrastructure

Docker Compose:

```text
postgres
redis
minio
api
ml
```

Do not deploy anything yet.

## Acceptance Criteria

* Docker Compose configuration validates.
* Laravel can connect to PostgreSQL.
* Laravel can connect to Redis.
* ML FastAPI exposes `/health`.
* Laravel exposes `/api/v1/health`.
* No business feature implemented yet.

---

# Phase 1 — Backend Foundation

## Goal

Prepare Laravel architecture before feature implementation.

## Work

Configure:

* database,
* API response convention,
* exception handling,
* API versioning,
* authentication foundation,
* testing foundation.

Create domain skeleton:

```text
Identity
School
Heritage
Geometry
Learning
Research
MachineLearning
```

Do not create every entity yet.

## Acceptance Criteria

* backend test suite passes,
* unauthenticated/authenticated API test exists,
* API errors have standardized format.

---

# Phase 2 — Identity & School

## Goal

A real student can authenticate.

## Implement

```text
User
School
StudentProfile
Classroom
ClassroomMember
```

Endpoints:

```text
POST /api/v1/auth/login
POST /api/v1/auth/logout
GET  /api/v1/me
```

## Tests

* valid student login,
* invalid credentials rejected,
* protected endpoint requires authentication.

---

# Phase 3 — Heritage Content

## Goal

Frontend team can build landing and Borobudur pages entirely from API data.

## Implement

```text
HeritageSite
HeritageArea
PanoramaNode
PanoramaLink
```

Endpoints:

```text
GET /api/v1/heritage-sites

GET /api/v1/heritage-sites/{slug}

GET /api/v1/heritage-sites/{slug}/areas

GET /api/v1/panorama-nodes/{id}
```

Seed development data:

```text
Borobudur
1 Area
3 Panorama Nodes
```

Use fake/dev object-storage URLs first.

Use Docker MinIO through Laravel's S3-compatible storage disk for local panorama binaries. Store only the object path/URL in PostgreSQL.

## Acceptance Criteria

A frontend developer can obtain:

```text
Borobudur
→ area
→ panorama A
→ navigation links B/C
```

without hardcoded application data.

---

# Phase 4 — Research Participant

## Goal

Separate student identity from research identity before storing assessment outcomes.

## Implement

```text
ResearchStudy
ResearchParticipant
```

Generate respondent code.

Example:

```text
RSP-X7M2P9
```

## Tests

Research result models must not require student name or student number.

---

# Phase 5 — Learning Session State Machine

## Goal

Backend controls the complete learning journey.

## Implement

```text
LearningSession
```

Phases:

```text
pretest
exploration
posttest
self_efficacy
completed
```

Implement secure session write token.

Endpoints:

```text
GET  /me/learning-sessions/active

POST /learning-sessions

GET  /learning-sessions/{id}
```

## Tests

Must test:

```text
Student A cannot access Student B session.

Invalid session token rejected.

Client cannot manually set phase.

New session begins in pretest.
```

---

# Phase 6 — Generic Assessment Engine

## Goal

One backend engine supports:

```text
pretest
posttest
self_efficacy
```

## Implement

```text
AssessmentInstrument
AssessmentItem
AssessmentOption
AssessmentAttempt
AssessmentAnswer
```

Endpoints:

```text
POST /learning-sessions/{id}/assessment-attempts

GET /assessment-attempts/{id}

PUT /assessment-attempts/{attemptId}/answers/{itemId}

POST /assessment-attempts/{attemptId}/submit
```

Start with dummy development questions.

## Acceptance Criteria

### Pretest

On submit:

```text
backend calculates score
→ locks attempt
→ session becomes exploration
```

Correct answers never appear in API responses.

### Common Rules

* clients submit item and selected-option IDs, never scores,
* selected options must belong to the submitted item and instrument,
* ownership, session write token, and allowed phase are enforced,
* submitted attempts cannot be modified,
* self-efficacy responses have no correctness or score.

---

# Phase 7 — Panorama Learning

## Goal

A student can enter and resume panorama exploration.

## Implement

```text
POST /learning-sessions/{id}/panorama-visits
```

Store:

```text
current_panorama_node
visited node
timestamp
```

Create meaningful activity events.

## Tests

* only exploration-phase sessions can visit,
* visiting updates current panorama,
* session resume returns correct current panorama.

---

# Phase 7A — Filament & Role Foundation

## Goal

Internal users can authenticate to a secured Filament panel without changing the student API contract.

## Implement

```text
Filament admin panel
UserRole
super_admin
researcher
teacher
student
```

Use Filament's native panel access contract.

Student API login accepts only student-role users with a student profile.

Do not add a permission package or scaffold domain resources in this foundation slice.

## Tests

* guests are redirected to Filament login,
* students cannot access Filament,
* super admins, researchers, and teachers can access Filament,
* internal users cannot authenticate through the student API.

---

# Phase 7B — Existing Domain Back-office

Add Filament resources as separate, verified tasks in this order:

```text
1. HeritageSite + HeritageArea
2. PanoramaNode + PanoramaLink
3. ResearchStudy + ResearchParticipant
4. AssessmentInstrument + nested AssessmentItem/AssessmentOption management
5. School + Classroom + Student
```

Assessment attempts and answers are system-generated records, not editable back-office content.

For each task:

* use the existing Eloquent/domain model,
* add policies before exposing the resource,
* verify unauthorized and role-scoped access,
* where an existing REST read path exists, verify it reflects Filament changes,
* do not duplicate REST controller business logic.

Do not implement all resources in one task.

---

# Phase 7C — Panorama Object Storage

## Goal

Internal users can upload panorama images to Docker MinIO through Laravel's S3-compatible storage disk.

## Implement

* configure the Laravel S3 disk and local MinIO environment values,
* provision the development panorama bucket during fresh Docker setup,
* replace manual panorama URL entry with a Filament image upload,
* store only an object path/URL in `panorama_nodes`, never image binaries,
* keep the existing REST `panorama_url` response usable by the student frontend.

## Tests

* panorama uploads use Laravel Storage rather than PostgreSQL,
* invalid file types and oversized uploads are rejected,
* replacing or deleting an upload does not orphan objects,
* a fresh Docker setup can write and read the development bucket,
* panorama REST responses contain a usable image URL.

---

# Phase 8 — Geometry & Learning Material

## Goal

Represent Borobudur objects correctly.

## Implement

```text
HeritageObject
PanoramaObjectAnnotation
GeometryShape
HeritageGeometryMapping
LearningObjective
Learning material content
```

Development data:

```text
Stupa
→ didekati sebagai
→ Setengah Bola
```

Endpoint:

```text
GET /heritage-objects/{id}/learning-material

POST /learning-sessions/{id}/materials/{objectId}/viewed
```

Material-view mutations must enforce session ownership, write token, and exploration phase, then create an activity event.

## Tests

* heritage objects remain separate from geometry shapes,
* mappings use approximation semantics,
* panorama annotations reference objects instead of encoding geometry classes,
* material views reject invalid owners, tokens, and session phases.

---

# Phase 8A — Geometry & Learning Back-office

Add Filament resources as separate, verified tasks after the Phase 8 domain models exist:

```text
1. HeritageObjectResource
2. GeometryShapeResource + HeritageGeometryMappingResource
3. LearningObjectiveResource + learning material content
```

Manage panorama object annotations through their owning panorama/object resource; do not create a standalone resource without an operational need.

Apply the same policy, role-scope, shared-domain-logic, and REST-visibility checks defined in Phase 7B.

---

# Phase 9 — Micro Quiz

## Goal

Allow formative learning assessment independently from research assessments.

## Implement

```text
Quiz
Question
QuestionOption
QuizAttempt
QuizAnswer
```

Endpoints:

```text
GET /quizzes/{id}

POST /learning-sessions/{id}/quiz-attempts

PUT /quiz-attempts/{attemptId}/answers/{questionId}

POST /quiz-attempts/{attemptId}/submit
```

Server calculates correctness.

Micro quiz may return immediate feedback.

## Tests

* micro quizzes remain separate from research assessments,
* the server calculates correctness,
* submitted attempts cannot be modified,
* immediate feedback never changes pretest/posttest response rules.

---

# Phase 9A — Quiz Back-office

Add:

```text
QuizResource
QuestionResource
```

Manage question options under their owning question instead of creating a standalone resource.

Apply the same policy, role-scope, and shared-domain-logic checks defined in Phase 7B.

---

# Phase 10 — Exploration Completion

## Goal

Backend decides whether required learning activity is complete.

Endpoint:

```text
GET /learning-sessions/{id}/progress

POST /learning-sessions/{id}/exploration/complete
```

MVP requirement:

```text
required panorama visited
AND
required learning material viewed
AND
required micro quiz completed
```

Then:

```text
exploration
→ posttest
```

## Tests

* incomplete requirements cannot advance the session,
* completion requires session ownership, write token, and exploration phase,
* a successful completion advances exactly once to posttest.

---

# Phase 11 — Posttest & Self-Efficacy

Reuse generic assessment engine.

Flow:

```text
posttest submitted
        ↓
self_efficacy

self_efficacy submitted
        ↓
completed
```

Add result endpoint:

```text
GET /learning-sessions/{id}/result
```

Do not expose research score comparisons to student by default.

## Tests

* posttest cannot start before exploration is complete,
* posttest submission transitions only to self-efficacy,
* self-efficacy submission transitions only to completed,
* result responses do not expose answer keys or unrestricted research comparisons.

---

# Phase 12 — ML Service Skeleton

Only start ML integration after backend learning flow works.

## Goal

Prove the service contract before integrating a trained model.

Implement:

```text
FastAPI

GET /health

POST /v1/predict
```

Create:

```text
Detector interface
DummyDetector
```

Prediction input must include:

```text
image
panorama_node_id
camera_yaw
camera_pitch
camera_fov
```

Dummy response:

```json
{
  "model_version": "dummy-v1",
  "inference_ms": 10,
  "detections": [
    {
      "class": "stupa",
      "confidence": 0.95,
      "bounding_box": [10, 20, 100, 120]
    }
  ]
}
```

## Acceptance Criteria

Detector logic can run without FastAPI.

Tests cover the detector interface, invalid images, API schema, and prediction response contract.

---

# Phase 13 — Laravel ↔ ML Integration

Endpoint:

```text
POST /learning-sessions/{id}/ml-predictions
```

Flow:

```text
request
↓
authorize session
↓
validate exploration phase
↓
call ML service
↓
save inference run
↓
save detections
↓
map detections to heritage/geometry content
↓
return prediction
```

Implement:

```text
MLModel
MLModelVersion
MLInferenceRun
MLDetection
```

Persist:

```text
model version
camera yaw
camera pitch
camera FOV
inference_ms
total_latency_ms
detection class
detection confidence
bounding box
```

Every successful prediction must be attributable to a model version. ML service failures must return a controlled API error without corrupting or advancing the learning session.

## Tests

* frontend-facing requests require session ownership, write token, and exploration phase,
* successful inference records model version, camera metadata, latency, detections, and confidence,
* ML service failure does not advance or corrupt the learning session,
* ML service never accesses the application database directly.

---

# Phase 13A — Machine Learning Back-office

Add:

```text
MLModelResource
MLModelVersionResource
MLInferenceRunResource
```

Inference runs and detections are read-only operational records. Model/version mutations remain restricted by policy.

Apply role-scoped policies and verify API-created inference records are visible in Filament without duplicating inference logic.

---

# Phase 14 — Real Detector

Replace:

```text
DummyDetector
```

with:

```text
YOLODetector
```

without changing FastAPI route contract or Laravel integration.

This is the reason detector abstraction exists.

## Tests

* model loading succeeds and reports its version,
* detector output satisfies the existing prediction contract,
* FastAPI remains only an adapter around reusable detector code.

---

# Phase 15 — ML Dataset Pipeline

Separate from runtime API.

Create:

```text
preprocessing/
training/
evaluation/
```

Dataset layout:

```text
dataset/

images/
├── train
├── val
└── test

labels/
├── train
├── val
└── test
```

Annotation:

```text
object-level bounding boxes
```

Split train, validation, and test data so near-identical crops from the same panorama cannot leak across splits.

Add preprocessing tests that can run without FastAPI or a training job.

---

# Phase 16 — ML Evaluation

Generate:

```text
confusion matrix
precision per class
recall per class
F1 per class
macro F1
dataset class distribution
```

Save reproducible experiment configuration.

Never report only a single validation accuracy.

---

# Phase 17 — Hardening

Before production pilot:

* authorization audit,
* session security review,
* API rate limits,
* ML request limits,
* object-storage validation,
* production logging,
* secret handling,
* backup strategy,
* API contract review,
* GitHub Actions quality gates for backend and ML tests,
* Nginx deployment-layer configuration,
* fresh-clone Docker Compose and migration/seeder verification.

Run full test suite.

Run Codex review against the complete backend diff.

---

# Task Size Rule

One Codex task should normally modify one coherent capability.

Good:

```text
Implement the LearningSession creation flow and its tests.
```

Bad:

```text
Implement authentication, sessions, quizzes, ML and deployment.
```

---

# Codex Execution Loop

For every phase use the following pattern.

## Step 1 — Planning

Tell Codex:

```text
Read AGENTS.md, docs/PRD.md and docs/IMPLEMENTATION_PLAN.md.

We are working on Phase N only.

Do not edit files yet.

Inspect the repository and propose a concrete implementation plan for this phase.

Include:
- files to create/change,
- schema changes,
- tests required,
- possible risks,
- anything ambiguous that needs a decision.

Do not plan future phases.
```

Review the plan manually.

---

## Step 2 — Implementation

After approving:

```text
Implement the approved Phase N plan.

Stay strictly within the phase scope.

Follow AGENTS.md.

Run relevant migrations/tests/lint.

Do not modify unrelated code.

When finished, report:
- files changed,
- implementation decisions,
- tests run,
- remaining risks.
```

---

## Step 3 — Review

Use Codex review after implementation.

Ask it specifically to check:

```text
authorization
session state integrity
data leaks
incorrect API contracts
missing tests
research-data integrity
overengineering
```

Fix real findings.

---

## Step 4 — Commit

Commit one coherent phase/task.

Example:

```text
feat(learning): add secure learning session creation
```

Do not combine unrelated work.

---

## Step 5 — Update Plan

Mark the phase complete only after:

```text
implementation ✓
tests ✓
review ✓
documentation ✓
```

Then start a fresh Codex task for the next phase.
