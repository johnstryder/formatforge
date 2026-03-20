/// Ensure source_links has url, title, status, metadata (legacy installs / empty schema).
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("source_links")
  } catch (_) {
    return
  }
  const names = new Set((col.fields || []).map((f) => f && f.name).filter(Boolean))
  const toAdd = []
  if (!names.has("url")) {
    toAdd.push(new TextField({ name: "url", max: 0 }))
  }
  if (!names.has("title")) {
    toAdd.push(new TextField({ name: "title", max: 0 }))
  }
  if (!names.has("status")) {
    toAdd.push(new TextField({ name: "status", max: 0 }))
  }
  if (!names.has("metadata")) {
    toAdd.push(new JSONField({ name: "metadata", maxSize: 0 }))
  }
  if (toAdd.length === 0) {
    return
  }
  col.fields = [...(col.fields || []), ...toAdd]
  app.save(col)
})
