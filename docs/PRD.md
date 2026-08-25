# PRD — VR-GeoNusa Backend, Admin Panel & Machine Learning V1

## 1. Document Status

**Product:** VR-GeoNusa  
**Scope:** Backend API + Filament Admin Panel + Machine Learning  
**Version:** V1 / Borobudur  
**Status:** Engineering Baseline / Frozen V1  
**Student Frontend:** Dikerjakan oleh tim frontend terpisah  
**Primary Destination:** Candi Borobudur  

---

## 2. Product Overview

VR-GeoNusa adalah platform pembelajaran berbasis Virtual Reality/WebXR yang membantu siswa SMP mempelajari konsep geometri melalui eksplorasi situs warisan budaya.

Versi pertama difokuskan pada **Candi Borobudur** menggunakan foto panorama 360° yang telah tersedia.

Student journey V1:

```text
Landing Page
    ↓
Borobudur Detail
    ↓
Login
    ↓
Borobudur Intro
    ↓
Pretest
    ↓
Panorama Exploration
    ↓
ML Identification
    ↓
Geometry Learning Material
    ↓
Micro Quiz
    ↓
Posttest
    ↓
Self-Efficacy
    ↓
Result
```

Backend bertanggung jawab atas seluruh business logic, state pembelajaran, data penelitian, penilaian, keamanan sesi, data heritage, integrasi Machine Learning, serta back-office internal melalui Filament.

Student frontend dikerjakan oleh tim terpisah dan mengonsumsi REST API berdasarkan OpenAPI contract.

---

# 3. Team Ownership

## Backend / ML Team

Bertanggung jawab atas:

```text
Laravel REST API
PostgreSQL
Redis
Filament Admin Panel
Teacher/Researcher back-office
Authentication logic
Authorization
Learning session state
Assessment scoring
Research data integrity
OpenAPI contract
FastAPI ML service
ML training/evaluation
Laravel ↔ ML integration
Object storage integration
```

## Student Frontend Team

Bertanggung jawab atas:

```text
Landing Page
Borobudur Detail
Login UI
Borobudur Intro
Pretest UI
Panorama 360° / WebXR
Panorama navigation UI
ML identification UI
Learning material UI
Micro Quiz UI
Progress UI
Posttest UI
Self-Efficacy UI
Result Page
```

## Shared Contract

Titik integrasi kedua tim adalah:

```text
contracts/openapi.yaml
```

Frontend tidak boleh mengarang business rule atau response structure permanen untuk menutupi API yang belum tersedia.

Jika frontend membutuhkan data baru:

```text
Frontend raises requirement
        ↓
Backend reviews contract
        ↓
OpenAPI updated
        ↓
Backend implementation
        ↓
Frontend consumption
```

---

# 4. V1 Scope

## Identity & School

- Student authentication
- Student profile
- School
- Classroom
- Classroom membership
- Research respondent code
- Role-based access for super admin, researcher, teacher, student

## Heritage

- Heritage site
- Heritage area
- Panorama node
- Panorama navigation graph
- Heritage object
- Panorama object annotation
- Geometry mapping
- Learning objective mapping

## Learning

- Learning session
- Session resume
- Learning objectives
- Learning material
- Micro quiz
- Progress tracking
- Activity events

## Research

- Research study
- Research participant
- Pretest
- Posttest
- Self-efficacy questionnaire
- Server-side scoring
- Research-safe respondent identifier

## Filament Back-office

- Super admin panel
- Heritage content management
- Panorama node/link management
- Geometry content management
- Learning material management
- Quiz management
- Research instrument management
- Research participant management
- School/class/student management
- ML model version monitoring
- ML inference log monitoring
- Basic teacher/researcher access based on role/policy

## Machine Learning

- Image inference endpoint
- Model versioning
- Detection result
- Confidence score
- Inference latency
- Total request latency
- Camera metadata
- Inference logging
- Training/evaluation pipeline separated from runtime inference

## Infrastructure

- PostgreSQL
- Redis
- Object storage abstraction
- MinIO for local development
- Docker Compose
- Automated tests
- OpenAPI contract
- GitHub Actions
- Nginx for deployment layer

---

# 5. Out of Scope V1

Tidak dikerjakan pada fase awal:

- Prambanan experience
- 3D geometry interaction
- advanced teacher analytics dashboard
- advanced researcher analytics dashboard
- advanced charts and comparative analytics
- leaderboard
- gamification
- certificate
- offline mode
- native mobile application
- continuous frame-by-frame ML inference
- ML training melalui web application
- dataset annotation UI
- custom React admin dashboard
- multi-language content
- Kubernetes
- microservice decomposition beyond the separate ML service

---

# 6. Technology Stack

## Backend

- Laravel
- PHP
- Laravel Sanctum
- Filament
- PostgreSQL
- Redis
- Laravel Queue
- Laravel Storage abstraction

## Machine Learning

- Python
- FastAPI
- PyTorch
- YOLO
- OpenCV
- ONNX optional

## Storage

Development:

- MinIO

Production:

- Cloudflare R2 atau S3-compatible storage

## Infrastructure

- Docker Compose
- Nginx
- GitHub Actions

## API Contract

- REST
- OpenAPI 3.x
- `/api/v1`

---

# 7. System Architecture

```text
Student Frontend Team
        │
        │ HTTPS / REST
        ▼
   Laravel Application
        │
        ├── REST API
        │     └── Student Frontend
        │
        ├── Filament
        │     ├── Super Admin
        │     ├── Researcher
        │     └── Teacher
        │
        ├── PostgreSQL
        ├── Redis
        ├── Object Storage
        │
        └── ML Integration
              │
              ▼
          FastAPI ML
              │
              ▼
            Model
```

Frontend tidak pernah berkomunikasi langsung dengan ML service.

```text
Frontend
   ↓
Laravel
   ↓
FastAPI
   ↓
Model
   ↓
Laravel
   ↓
Frontend
```

ML service tidak diperbolehkan mengakses database aplikasi secara langsung.

Filament dan REST API harus menggunakan business logic/domain layer yang sama.

```text
Student REST API ──┐
                   │
                   ▼
           Application / Domain
                   ▲
                   │
Filament Admin ─────┘
```

Business rules tidak boleh diduplikasi secara terpisah di API Controller dan Filament Resource.

---

# 8. Repository Architecture

```text
vr-geonusa/

├── apps/
│   └── api/
│       ├── app/
│       │   ├── Domain/
│       │   ├── Filament/
│       │   ├── Http/
│       │   └── Infrastructure/
│       ├── database/
│       ├── routes/
│       └── tests/
│
├── services/
│   └── ml/
│       ├── app/
│       ├── inference/
│       ├── preprocessing/
│       ├── training/
│       ├── evaluation/
│       └── tests/
│
├── contracts/
│   └── openapi.yaml
│
├── infrastructure/
│   ├── docker/
│   └── nginx/
│
├── docs/
│   ├── PRD.md
│   ├── ARCHITECTURE.md
│   ├── DATABASE.md
│   └── IMPLEMENTATION_PLAN.md
│
├── AGENTS.md
├── docker-compose.yml
├── .env.example
└── README.md
```

---

# 9. Backend Architecture

Backend menggunakan **modular monolith**.

Domain utama:

```text
Identity
School
Heritage
Geometry
Learning
Research
MachineLearning
```

Filament bukan domain terpisah. Filament adalah presentation/back-office layer yang menggunakan domain yang sama dengan REST API.

Suggested structure:

```text
app/

├── Domain/
│   ├── Identity/
│   ├── School/
│   ├── Heritage/
│   ├── Geometry/
│   ├── Learning/
│   ├── Research/
│   └── MachineLearning/
│
├── Http/
├── Filament/
├── Infrastructure/
└── Providers/
```

Controllers harus tipis:

```text
HTTP Request
    ↓
Validation
    ↓
Application Action
    ↓
Domain Logic
    ↓
Persistence / External Service
    ↓
HTTP Response
```

Jangan membuat abstraction tanpa use case nyata.

---

# 10. Core Entities

## Identity & School

```text
User
StudentProfile
School
Classroom
ClassroomMember
```

## Research

```text
ResearchStudy
ResearchParticipant
AssessmentInstrument
AssessmentItem
AssessmentOption
AssessmentAttempt
AssessmentAnswer
```

## Heritage

```text
HeritageSite
HeritageArea
PanoramaNode
PanoramaLink
HeritageObject
PanoramaObjectAnnotation
```

## Geometry

```text
GeometryShape
HeritageGeometryMapping
LearningObjective
```

## Learning

```text
LearningSession
ActivityEvent
Quiz
Question
QuestionOption
QuizAttempt
QuizAnswer
```

## Machine Learning

```text
MLModel
MLModelVersion
MLInferenceRun
MLDetection
```

---

# 11. Critical Data Rules

## Student Identity vs Research Participant

Data identitas siswa dan data penelitian harus dipisahkan.

```text
StudentProfile
├── name
├── student_number
└── school
```

berbeda dengan:

```text
ResearchParticipant
└── respondent_code
```

Data assessment menggunakan `research_participant_id`, bukan nama atau NIS secara langsung.

## Heritage Object vs Geometry Shape

Elemen budaya bukan bangun geometri secara literal.

```text
HeritageObject
Stupa
    ↓
HeritageGeometryMapping
    ↓
GeometryShape
Setengah Bola
```

Hubungannya menggunakan semantics:

```text
"didekati sebagai"
```

---

# 12. Learning Session State Machine

Learning session adalah source of truth perjalanan siswa.

```text
PRETEST
    ↓
EXPLORATION
    ↓
POSTTEST
    ↓
SELF_EFFICACY
    ↓
COMPLETED
```

Backend mengontrol seluruh state transition.

Frontend tidak boleh mengubah phase secara langsung.

Tidak diperbolehkan:

```text
PATCH /session
phase = completed
```

State hanya berubah sebagai konsekuensi action yang valid.

---

# 13. Session Security

Ketika learning session dibuat, backend menghasilkan random session write token.

Semua operasi mutasi session harus memvalidasi:

```text
authenticated user
        ↓
session ownership
        ↓
session write token
        ↓
current session state
        ↓
request validation
        ↓
action
```

Raw token tidak disimpan di database. Hanya hash token yang disimpan.

---

# 14. Assessment Rules

Ada dua kategori assessment.

## Research Assessment

```text
Pretest
Posttest
Self-Efficacy
```

## Learning Assessment

```text
Micro Quiz
```

Keduanya tidak boleh dicampur.

### Pretest / Posttest

Frontend menerima:

```text
item id
question text
option id
option text
```

Frontend tidak pernah menerima:

```text
correct answer
is_correct
```

Frontend mengirim:

```text
item_id
selected_option_id
```

Backend menghitung nilai dan mengunci submitted attempt.

### Micro Quiz

Micro quiz juga dinilai oleh backend.

Setelah jawaban disubmit, backend boleh mengembalikan:

```text
correct / incorrect
feedback
```

agar siswa mendapat formative feedback.

### Self-Efficacy

Self-efficacy tidak memiliki jawaban benar/salah dan menggunakan assessment engine yang sama dengan item type yang sesuai.

---

# 15. Panorama Model

Panorama menggunakan graph.

```text
Panorama A
    │
    ├── Panorama B
    └── Panorama C
```

Bukan sekadar `previous` / `next`.

`PanoramaLink` menyimpan:

```text
source_node
target_node
yaw
pitch
label
```

Panorama binary asset tidak disimpan di PostgreSQL.

---

# 16. Machine Learning Flow

```text
Student melihat panorama
        ↓
Student menekan "Identifikasi"
        ↓
Frontend mengambil viewport image
        ↓
Laravel menerima image + camera metadata
        ↓
Laravel authorize session
        ↓
Laravel memanggil ML Service
        ↓
ML inference
        ↓
Laravel menerima detection
        ↓
Laravel menyimpan inference
        ↓
Laravel melakukan heritage/geometry mapping
        ↓
Frontend menerima result
```

Inference V1 bersifat:

```text
student-triggered
```

Bukan continuous inference setiap frame.

---

# 17. ML Prediction Contract

## Input Minimum

```text
image
panorama_node_id
camera_yaw
camera_pitch
camera_fov
```

## ML Service Output

```text
model_version
inference_ms
detections[]
    class
    confidence
    bounding_box
```

## Laravel Persistence

Laravel menyimpan:

```text
learning_session
model_version
panorama_node
camera metadata
inference time
total latency
detections
confidence
```

Setiap prediction harus dapat ditelusuri ke model version yang digunakan.

---

# 18. ML Architecture Rules

Training pipeline dan inference runtime harus dipisahkan.

```text
preprocessing/
training/
evaluation/
inference/
```

FastAPI hanya menjadi inference adapter.

Core detector harus dapat dipanggil tanpa HTTP:

```python
detector.predict(image)
```

ML service tidak menangani authentication, learning progress, research scoring, atau direct application database access.

---

# 19. ML Dataset Principles

Unit anotasi:

```text
object-level bounding box
```

bukan label per panorama.

Dataset harus dipisahkan menjadi:

```text
train
validation
test
```

Evaluation minimal menghasilkan:

```text
confusion matrix
precision per class
recall per class
F1 per class
macro F1
dataset class distribution
```

Hindari data leakage antara crop yang sangat mirip dari sumber panorama yang sama.

Model artifact harus versioned.

---

# 20. Filament Back-office V1

Filament berfungsi sebagai back-office operasional, bukan analytics product penuh.

## Identity & School

```text
SchoolResource
ClassroomResource
StudentResource
```

## Heritage

```text
HeritageSiteResource
HeritageAreaResource
PanoramaNodeResource
PanoramaLinkResource
HeritageObjectResource
```

## Geometry & Learning

```text
GeometryShapeResource
HeritageGeometryMappingResource
LearningObjectiveResource
QuizResource
QuestionResource
```

## Research

```text
ResearchStudyResource
ResearchParticipantResource
AssessmentInstrumentResource
```

## Machine Learning

```text
MLModelResource
MLModelVersionResource
MLInferenceRunResource
```

Tidak semua resource harus dibuat sekaligus. Resource mengikuti phase/domain yang sudah tersedia.

Prioritas Filament V1:

```text
1. Heritage content
2. Panorama configuration
3. Geometry mapping
4. Learning/quiz content
5. Research instruments
6. Research participants
7. School/student management
8. ML model/inference monitoring
```

Teacher/researcher pada V1 cukup menggunakan role/policy untuk melihat atau mengelola data sesuai kebutuhan. Advanced dashboard ditunda sampai kebutuhan nyata dan data pilot tersedia.

---

# 21. API Principles

Prefix:

```text
/api/v1
```

Successful resource:

```json
{
  "data": {}
}
```

Error:

```json
{
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message"
  }
}
```

Student frontend contract berasal dari:

```text
contracts/openapi.yaml
```

Perubahan API harus memperbarui OpenAPI contract pada perubahan yang sama.

Filament tidak menggunakan OpenAPI sebagai transport karena berjalan di dalam Laravel application yang sama.

---

# 22. Core Student API V1

## Public

```text
GET /heritage-sites
GET /heritage-sites/{slug}
```

## Authentication

```text
POST /auth/login
POST /auth/logout
GET  /me
```

## Sessions

```text
GET  /me/learning-sessions/active
POST /learning-sessions
GET  /learning-sessions/{id}
GET  /learning-sessions/{id}/progress
```

## Panorama

```text
GET  /heritage-sites/{slug}/areas
GET  /panorama-nodes/{id}
POST /learning-sessions/{id}/panorama-visits
```

## Machine Learning

```text
POST /learning-sessions/{id}/ml-predictions
```

## Learning Material

```text
GET  /heritage-objects/{id}/learning-material
POST /learning-sessions/{id}/materials/{objectId}/viewed
```

## Micro Quiz

```text
GET  /quizzes/{id}
POST /learning-sessions/{id}/quiz-attempts
PUT  /quiz-attempts/{attemptId}/answers/{questionId}
POST /quiz-attempts/{attemptId}/submit
```

## Assessment

```text
POST /learning-sessions/{id}/assessment-attempts
GET  /assessment-attempts/{id}
PUT  /assessment-attempts/{attemptId}/answers/{itemId}
POST /assessment-attempts/{attemptId}/submit
```

## Exploration

```text
POST /learning-sessions/{id}/exploration/complete
```

## Result

```text
GET /learning-sessions/{id}/result
```

---

# 23. Backend Testing Requirements

Critical flows harus mempunyai feature tests.

Minimal:

```text
student can login
student can create own session
student cannot access another student's session
invalid session token is rejected
pretest cannot be skipped
posttest cannot start before exploration completes
frontend cannot submit its own score
submitted assessment cannot be modified
ML request records model version
ML request records latency
ML service failure does not crash learning session
Filament unauthorized user cannot access restricted resources
teacher cannot access restricted data outside allowed scope
researcher access follows configured policies
```

Filament authorization harus menggunakan Laravel policies/permissions yang relevan dan tidak mengandung business-rule duplicate.

---

# 24. ML Testing Requirements

Minimum:

```text
preprocessing unit tests
detector interface tests
API schema tests
invalid-image tests
model-loading tests
prediction response contract tests
```

Model quality evaluation terpisah dari runtime API tests.

---

# 25. Definition of Done

Sebuah backend feature dianggap selesai hanya jika:

1. requirement implemented,
2. migration tersedia bila diperlukan,
3. request validation tersedia,
4. authorization tersedia,
5. feature/unit tests tersedia,
6. test suite lulus,
7. OpenAPI contract diperbarui jika API berubah,
8. tidak ada secret atau credential hardcoded,
9. relevant documentation diperbarui.

Jika feature mempunyai Filament management UI:

10. Filament resource/action tersedia sesuai scope,
11. authorization/policy diterapkan,
12. Filament menggunakan application/domain logic yang sama dan tidak menduplikasi business logic.

Sebuah ML feature dianggap selesai hanya jika:

1. preprocessing teruji,
2. detector dapat digunakan tanpa FastAPI,
3. API adapter tersedia bila diperlukan,
4. tests lulus,
5. model version tercatat,
6. error handling tersedia,
7. latency dapat diukur,
8. response sesuai contract.

---

# 26. MVP Engineering Targets

## Vertical Slice 1 — Core Student Flow

```text
Student authentication
        ↓
Create learning session
        ↓
Pretest dummy
        ↓
Load one panorama metadata
        ↓
Record panorama visit
```

## Vertical Slice 2 — Learning Content

```text
Heritage object
        ↓
Geometry mapping
        ↓
Learning material
        ↓
Micro quiz
```

## Vertical Slice 3 — ML Integration

```text
Image request
        ↓
Laravel
        ↓
ML service
        ↓
Dummy detector
        ↓
Prediction persistence
```

Model ML sungguhan baru menggantikan dummy detector setelah integration contract terbukti stabil.

## Vertical Slice 4 — Back-office

```text
Filament login
        ↓
Manage Borobudur heritage site
        ↓
Manage 1 area
        ↓
Manage 3 panorama nodes
        ↓
Manage panorama links
        ↓
REST API reflects the changes
```

Data yang diubah melalui Filament harus menjadi source data yang sama dengan data yang dikonsumsi Student REST API.

---

# 27. Development Content for V1

Jangan menunggu semua data Borobudur siap.

Development awal cukup menggunakan:

```text
1 heritage site
1 area
3 panorama nodes
1 heritage object
1 geometry mapping
1 learning objective
1 learning material
1 micro quiz
1 pretest instrument
1 posttest instrument
1 self-efficacy instrument
1 dummy ML detector
```

Setelah vertical slice end-to-end stabil, konten dapat diperluas tanpa mengubah architecture.

---

# 28. V1 Success Criteria

V1 dianggap memiliki fondasi yang benar ketika:

- backend dapat dijalankan dari fresh clone,
- seluruh dependency local tersedia melalui Docker,
- migrations dapat berjalan dari database kosong,
- student dapat menyelesaikan satu session end-to-end,
- session state tidak dapat dilewati,
- grading seluruh assessment dilakukan server-side,
- research identity menggunakan respondent code,
- panorama navigation bersifat data-driven dan graph-based,
- ML service dapat dipanggil melalui Laravel,
- inference tersimpan dengan model version dan latency,
- OpenAPI contract dapat digunakan tim frontend,
- Filament dapat mengelola konten Borobudur tanpa edit source code,
- perubahan heritage/panorama melalui Filament muncul melalui REST API,
- basic role access untuk super admin/researcher/teacher dapat diterapkan tanpa custom frontend terpisah,
- critical backend dan ML test suite lulus.

---

# 29. Main Engineering Principles

1. Laravel adalah source of truth untuk business logic.
2. Filament dan REST API menggunakan application/domain logic yang sama.
3. Student frontend hanya merender server state dan mengirim user actions.
4. Research scoring tidak pernah dihitung frontend.
5. Student identity dipisahkan dari research results.
6. Panorama navigation bersifat graph-based.
7. Heritage object dan geometry shape adalah konsep berbeda.
8. Frontend tidak pernah memanggil ML service secara langsung.
9. ML prediction selalu dapat ditelusuri ke model version.
10. Training/evaluation ML dipisahkan dari inference API.
11. Implementasi dilakukan per vertical slice dan diuji sebelum pindah phase.
12. Jangan menambah abstraction, dependency, atau service tanpa kebutuhan nyata.