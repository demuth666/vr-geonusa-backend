# Keep inference images ephemeral and resolve classes in Laravel

Student camera images are sent as bounded multipart uploads through Laravel to the ML service for synchronous inference and are not retained by either runtime application. The ML service returns normalized, named bounding-box coordinates and stable class keys; Laravel resolves those keys through mappings belonging to the reported model version, preserving Laravel’s ownership of heritage content while allowing detector labels to evolve.

## Consequences

Laravel records successful and failed Inference Runs for operational and research attribution without storing raw images, stack traces, or fabricated detections. Unknown class keys remain observable but do not link to learning material. Any future dataset collection requires a separate, explicitly consented workflow.
