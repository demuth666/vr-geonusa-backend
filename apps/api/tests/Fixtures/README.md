# Test fixtures

`real-detector-viewport.jpg` is the `bus.jpg` sample shipped with the Ultralytics
assets (<https://github.com/ultralytics/assets>). It is used only to exercise the pinned
stock COCO detector through the Laravel endpoint; the stock model reports `person` and
`bus` for this viewport, exactly the unresolved class keys the integration expects.
