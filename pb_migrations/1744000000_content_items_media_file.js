/// Upgrade existing installs: add media_file to content_items (already in 1731820800 for brand-new DBs).
/// Idempotent — skip if the field exists. PocketBase-hosted media: /api/files/{collectionId}/{recordId}/{filename}
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("content_items")
  } catch (_) {
    return
  }
  const names = new Set((col.fields || []).map((f) => f && f.name).filter(Boolean))
  if (names.has("media_file")) {
    return
  }
  col.fields = [
    ...(col.fields || []),
    new FileField({
      name: "media_file",
      maxSelect: 1,
      maxSize: 1073741824,
      mimeTypes: [],
    }),
  ]
  app.save(col)
})
