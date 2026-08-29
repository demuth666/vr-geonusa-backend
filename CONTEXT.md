# VR-GeoNusa

VR-GeoNusa models a research-oriented learning journey in which students study geometry through approximations of Borobudur heritage objects.

## Heritage and geometry

**Heritage Object**:
A culturally meaningful physical feature permanently associated with one heritage site after creation. It remains distinct from any mathematical shape used to explain it.
_Avoid_: Geometry object, geometry class

**Unused Heritage Object**:
A Heritage Object with no current or historical domain references. Only an Unused Heritage Object may be permanently deleted.
_Avoid_: Inactive object, unpublished object

**Geometry Shape**:
A mathematical form used in learning content, such as a hemisphere.
_Avoid_: Heritage class, object label

**Heritage Geometry Mapping**:
The approximation relationship connecting a Heritage Object to a Geometry Shape, expressed as “didekati sebagai.” It is the subject assessed by a Micro Quiz.
_Avoid_: Object classification, exact shape

## Learning journey

**Learning Experience**:
A research-study-specific selection of required panorama visits, learning materials, and Micro Quizzes that a student completes during exploration.
_Avoid_: Course, lesson configuration

**Learning Experience Revision**:
An immutable, published version of a Learning Experience to which a Learning Session is permanently assigned.
_Avoid_: Current experience, latest configuration

**Micro Quiz**:
A formative assessment of one Heritage Geometry Mapping that may provide immediate correctness feedback and explanation. It is separate from research pretests and posttests.
_Avoid_: Assessment Instrument, research assessment

**Completed Micro Quiz**:
A Micro Quiz for which the student has submitted one fully answered attempt, regardless of score. Later practice attempts do not revoke completion.
_Avoid_: Passed quiz, mastered quiz

**Required Learning Activity**:
An activity selected by a Learning Experience as necessary for exploration completion.
_Avoid_: All published content, globally required content

**ML Identification**:
An expected but non-gating exploration activity in which a captured image is analyzed for heritage-object detections. Failure or unavailability does not prevent completion of the V1 learning journey.
_Avoid_: Completion requirement, geometry classification

**ML Class Mapping**:
A model-version-specific association from a detector class key to a Heritage Object. An unknown class key remains a recorded detection but does not resolve to learning content.
_Avoid_: Heritage-object slug, geometry class

**Inference Run**:
One attributed attempt to analyze a student-submitted image, whether it succeeds or fails. It retains operational metadata and detections but not the submitted image.
_Avoid_: Prediction result, stored capture

**Student Result**:
The completed student’s own aggregate pretest and posttest scores and learning-activity summary. It excludes answer-level correctness, answer keys, self-efficacy scoring, and cohort comparisons.
_Avoid_: Research report, cohort analysis
