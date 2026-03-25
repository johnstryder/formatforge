/// Add scheduled_publish_at for auto-post queue (approved → scheduled → published).
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("content_items")
  } catch (_) {
    return
  }
  const names = new Set((col.fields || []).map((f) => f && f.name).filter(Boolean))
  if (names.has("scheduled_publish_at")) {
    return
  }
  col.fields = [
    ...(col.fields || []),
    new DateField({
      name: "scheduled_publish_at",
      required: false,
    }),
  ]
  app.save(col)
})
