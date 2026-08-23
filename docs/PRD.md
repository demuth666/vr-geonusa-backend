# PRD — VR-GeoNusa Backend & Machine Learning V1

## 1. Document Status

**Product:** VR-GeoNusa
**Scope:** Backend + Machine Learning
**Version:** V1 / Borobudur
**Status:** Initial Engineering Baseline
**Frontend:** Dikerjakan oleh tim terpisah
**Primary destination:** Candi Borobudur

---

## 2. Product Overview

VR-GeoNusa adalah platform pembelajaran berbasis Virtual Reality/WebXR yang membantu siswa SMP mempelajari konsep geometri melalui eksplorasi situs warisan budaya.

Versi pertama difokuskan pada **Candi Borobudur**, menggunakan foto panorama 360° yang telah tersedia.

Siswa akan:

1. membuka landing page,
2. memilih Candi Borobudur,
3. login,
4. mengikuti pretest,
5. menjelajahi panorama,
6. melakukan identifikasi elemen arsitektur menggunakan Machine Learning,
7. melihat hubungan elemen budaya dengan bangun ruang,
8. mengerjakan micro quiz,
9. menyelesaikan posttest,
10. mengisi kuesioner self-efficacy.

Backend bertanggung jawab atas seluruh state pembelajaran, data penelitian, penilaian, keamanan sesi, data heritage, dan integrasi Machine Learning.

---

# 3. V1 Scope

## In Scope

### Identity

* Student authentication
* Student profile
* School
* Classroom
* Research respondent code

### Heritage

* Heritage site
* Heritage area
* Panorama node
* Panorama navigation graph
* Heritage object
* Geometry mapping

### Learning

* Learning session
* Session resume
* Learning objectives
* Learning material
* Micro quiz
* Progress tracking
* Activity events

### Research

* Pretest
* Posttest
* Self-efficacy questionnaire
* Research participant
* Server-side scoring
* Research-safe respondent identifier

### Machine Learning

* Image inference endpoint
* Model versioning
* Detection result
* Confidence score
* Inference latency
* Total request latency
* Camera metadata
* Inference logging

### Infrastructure

* PostgreSQL
* Redis
* Object storage abstraction
* Docker Compose
* Automated tests
* OpenAPI contract

---

# 4. Out of Scope V1

Tidak dikerjakan pada fase awal:

* Prambanan
* 3D geometry interaction
* teacher dashboard lengkap
* leaderboard
* gamification
* certificate
* offline mode
* mobile native application
* continuous frame-by-frame ML inference
* ML training melalui web application
* dataset annotation UI
* advanced analytics dashboard
* multi-language content

---

# 5. Technology Stack

## Backend

* Laravel
* PHP
* Laravel Sanctum
* PostgreSQL
* Redis
* Laravel Queue
* Laravel Storage abstraction

## Machine Learning

* Python
* FastAPI
* PyTorch
* YOLO
* OpenCV
* ONNX optional

## Storage

Development:

* MinIO

Production:

* Cloudflare R2 atau S3-compatible storage

## Infrastructure

* Docker Compose
* Nginx
* GitHub Actions

## API Contract

* REST
* OpenAPI 3.x
* `/api/v1`

---

# 6. System Architecture

```text
Frontend Team
     │
     │ HTTPS / REST
     ▼
Laravel API
     │
     ├──────── PostgreSQL
     │
     ├──────── Redis
     │
     ├──────── Object Storage
     │
     └──────── ML Service
                   │
                   ▼
              FastAPI
                   │
                   ▼
               ML Model
```

Frontend tidak berkomunikasi langsung dengan ML service.

Semua request ML harus melalui Laravel.

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

---

# 7. Repository Architecture

```text
vr-geonusa/

├── apps/
│   └── api/
│       ├── app/
│       ├── database/
│       ├── routes/
│       └── tests/
│
├── services/
│   └── ml/
│       ├── app/
│       ├── inference/
│       ├── training/
│       ├── evaluation/
│       ├── preprocessing/
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

# 8. Backend Domains

Backend menggunakan modular monolith.

```text
Identity
School
Heritage
Geometry
Learning
Research
MachineLearning
```

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
├── Infrastructure/
└── Providers/
```

Business logic tidak diletakkan langsung di controller.

Controller hanya bertanggung jawab terhadap:

```text
HTTP request
    ↓
validation
    ↓
application action
    ↓
HTTP response
```

---

# 9. Core Entities

## Identity

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

# 10. Critical Data Rules

## Student identity

Data identitas siswa dan data penelitian harus dipisahkan.

```text
StudentProfile

name
student_number
school
```

berbeda dengan:

```text
ResearchParticipant

respondent_code
```

Data assessment menggunakan `research_participant_id`, bukan nama atau NIS secara langsung.

---

## Heritage vs Geometry

Elemen budaya bukan bangun geometri secara literal.

```text
HeritageObject
Stupa
```

dipetakan melalui:

```text
HeritageGeometryMapping
```

menjadi:

```text
GeometryShape
Setengah Bola
```

Hubungannya:

```text
"didekati sebagai"
```

---

# 11. Learning Session State Machine

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

Backend mengontrol state transition.

Frontend tidak boleh mengubah state secara langsung.

Tidak diperbolehkan:

```text
PATCH /session
phase = completed
```

State hanya berubah karena action yang valid.

---

# 12. Session Security

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

Raw token tidak disimpan di database.

Hanya hash token yang disimpan.

---

# 13. Assessment Rules

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

---

## Pretest / Posttest

Frontend hanya menerima:

```text
question
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

Backend menghitung nilai.

---

## Micro Quiz

Micro quiz juga dinilai oleh backend.

Namun setelah answer disubmit, backend boleh mengembalikan:

```text
correct / incorrect
feedback
```

agar siswa mendapat feedback pembelajaran.

---

# 14. Panorama Model

Panorama menggunakan graph.

```text
Panorama A
    │
    ├── Panorama B
    └── Panorama C
```

Bukan sekadar:

```text
previous
next
```

`PanoramaLink` menyimpan:

```text
source_node
target_node
yaw
pitch
label
```

---

# 15. Machine Learning Flow

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
Laravel melakukan geometry mapping
        ↓
Frontend menerima result
```

Tidak menggunakan continuous inference setiap frame.

Inference bersifat:

```text
student-triggered
```

---

# 16. ML Prediction Input

Minimum input:

```text
image
panorama_node_id
camera_yaw
camera_pitch
camera_fov
```

---

# 17. ML Prediction Output

ML service mengembalikan:

```text
model_version

inference_ms

detections[]
    class
    confidence
    bounding_box
```

Laravel bertanggung jawab menyimpan:

```text
learning_session

model_version

panorama_node

camera metadata

inference time

total latency

detections
```

---

# 18. ML Architecture Rules

Training pipeline dan inference runtime harus dipisahkan.

```text
training/
evaluation/
preprocessing/
```

tidak boleh bergantung pada FastAPI.

FastAPI hanya bertanggung jawab sebagai inference adapter.

Core detector harus dapat dipanggil:

```python
detector.predict(image)
```

tanpa HTTP.

---

# 19. ML Dataset Principles

Unit anotasi:

```text
object-level bounding box
```

bukan label per panorama.

Dataset harus mendukung:

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
```

Model artifact harus versioned.

---

# 20. API Principles

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

Frontend contract berasal dari:

```text
contracts/openapi.yaml
```

API yang berubah harus mengubah OpenAPI contract dalam commit yang sama.

---

# 21. Core API V1

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

## ML

```text
POST /learning-sessions/{id}/ml-predictions
```

## Learning

```text
GET  /heritage-objects/{id}/learning-material

POST /learning-sessions/{id}/materials/{objectId}/viewed
```

## Quiz

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

# 22. Backend Testing Requirements

Critical flows harus mempunyai feature tests.

Minimal test:

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
```

---

# 23. ML Testing Requirements

Minimum:

```text
preprocessing unit tests

detector interface tests

API schema tests

invalid-image tests

model-loading tests

prediction response contract tests
```

Model quality evaluation terpisah dari API tests.

---

# 24. Definition of Done

Sebuah backend feature dianggap selesai hanya jika:

1. requirement implemented,
2. migration tersedia bila diperlukan,
3. request validation tersedia,
4. authorization tersedia,
5. feature/unit tests tersedia,
6. test suite lulus,
7. API contract diperbarui,
8. tidak ada secret atau credential hardcoded,
9. relevant documentation diperbarui.

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

# 25. MVP Engineering Target

Vertical slice pertama:

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

Vertical slice kedua:

```text
Heritage object
        ↓
Geometry mapping
        ↓
Learning material
        ↓
Micro quiz
```

Vertical slice ketiga:

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

---

# 26. V1 Success Criteria

V1 backend dianggap memiliki fondasi yang benar ketika:

* backend dapat dijalankan dari fresh clone,
* seluruh dependency local tersedia melalui Docker,
* migrations dapat berjalan dari database kosong,
* student dapat menyelesaikan satu session end-to-end,
* session state tidak dapat dilewati,
* grading seluruh assessment dilakukan server-side,
* research identity menggunakan respondent code,
* panorama navigation data dapat diberikan ke frontend,
* ML service dapat dipanggil melalui Laravel,
* inference tersimpan dengan model version dan latency,
* OpenAPI contract dapat digunakan tim frontend,
* critical test suite lulus.
