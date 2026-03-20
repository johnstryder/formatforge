/// Pipelines are listed and run by dashboard users; only superusers (Cursor / admin API) may create/update/delete.
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("pipelines")
  } catch (_) {
    return
  }
  col.createRule = null
  col.updateRule = null
  col.deleteRule = null
  app.save(col)
})
