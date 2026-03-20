/// Pipelines are listed and run by dashboard users; only superusers (e.g. Pi via admin API) may create/update/delete.
migrate((db) => {
  const dao = new Dao(db)
  let col
  try {
    col = dao.findCollectionByNameOrId("pipelines")
  } catch (_) {
    return
  }
  col.createRule = null
  col.updateRule = null
  col.deleteRule = null
  dao.saveCollection(col)
})
