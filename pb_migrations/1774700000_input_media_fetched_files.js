/// Idempotent: multi-file uploads from the Fetch UI live on input_media.fetched_files (PocketBase storage).
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("input_media")
  } catch (_) {
    return
  }
  const names = new Set((col.fields || []).map((f) => f && f.name).filter(Boolean))
  if (names.has("fetched_files")) {
    return
  }
  col.fields = [
    ...(col.fields || []),
    {
      name: "fetched_files",
      type: "file",
      maxSelect: 20,
      maxSize: 1073741824,
      mimeTypes: [],
    },
  ]
  app.save(col)
})
