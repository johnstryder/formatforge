/// Drops the `pipelines` collection (no longer used by the app).
/// Idempotent: no-op if the collection does not exist.
migrate((app) => {
  try {
    const c = app.findCollectionByNameOrId("pipelines")
    app.delete(c)
  } catch (_) {}
})
