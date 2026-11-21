# Resolve detector classes to Geometry Shapes, not Heritage Objects

The V1 geometry detector reports mathematical shape labels such as `setengah_bola`, not heritage-object identities. A detected shape does not identify one heritage object, and one shape may be the approximation used by several objects. An `MlClassMapping` therefore associates a model-version class key with a `GeometryShape`.

The prediction response resolves each detection to its shape and then names the Heritage Objects that are annotated on the submitted panorama node and whose `HeritageGeometryMapping` uses that shape. Each heritage mapping keeps its `semantics`, such as “didekati sebagai”. An unknown class key or a class key mapped for another model version remains a persisted detection but resolves to `null` and an empty heritage-mapping list.

## Consequences

`MlClassMapping` no longer protects or references a Heritage Object, so deleting an unused Heritage Object only checks panorama annotations, geometry mappings, and activity history. Filament continues to author shapes and approximations independently of detector labels. Replacing or retraining a detector only requires class mappings for the model version it reports; it does not reclassify heritage content.
