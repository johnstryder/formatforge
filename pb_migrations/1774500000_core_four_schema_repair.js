/// When input_media / output_media / social_accounts exist with only system fields (e.g. manual
/// collection or failed partial migrate), add missing columns to match 1774300000 core-four schema.
/// Idempotent: skips fields that already exist. Uses Field constructors (plain {type:"text"} breaks save).
migrate((app) => {
  const namesOf = (col) =>
    new Set((col.fields || []).map((f) => f && f.name).filter(Boolean))

  const mergeFields = (collectionName, toAdd) => {
    let col
    try {
      col = app.findCollectionByNameOrId(collectionName)
    } catch (_) {
      return
    }
    const have = namesOf(col)
    const extra = toAdd.filter((f) => f && f.name && !have.has(f.name))
    if (extra.length === 0) {
      return
    }
    col.fields = [...(col.fields || []), ...extra]
    app.save(col)
  }

  mergeFields("social_accounts", [
    new TextField({ name: "platform", max: 0 }),
    new TextField({ name: "instagram_user_id", max: 0 }),
    new TextField({ name: "username", max: 0 }),
    new TextField({ name: "access_token", max: 0 }),
    new DateField({ name: "token_expires_at" }),
    new BoolField({ name: "is_active" }),
    new JSONField({ name: "metadata", maxSize: 0 }),
  ])

  mergeFields("input_media", [
    new TextField({ name: "role", max: 0 }),
    new TextField({ name: "status", max: 0 }),
    new TextField({ name: "url", max: 0 }),
    new TextField({ name: "title", max: 0 }),
    new JSONField({ name: "metadata", maxSize: 0 }),
    new TextField({ name: "pipeline_id", max: 0 }),
    new NumberField({ name: "slot_index" }),
    new TextField({ name: "slot_kind", max: 0 }),
    new TextField({ name: "topic", max: 0 }),
    new TextField({ name: "title_seed", max: 0 }),
    new TextField({ name: "input_url", max: 0 }),
    new TextField({ name: "instruction", max: 0 }),
    new BoolField({ name: "is_active" }),
    new VectorField({ name: "embedding", required: false }),
    new TextField({ name: "embedding_model", max: 0 }),
  ])

  mergeFields("output_media", [
    new TextField({ name: "type", max: 0 }),
    new TextField({ name: "title", max: 0 }),
    new TextField({ name: "prompt", max: 0 }),
    new TextField({ name: "input_media_id", max: 0 }),
    new TextField({ name: "garage_key", max: 0 }),
    new TextField({ name: "garage_url", max: 0 }),
    new TextField({ name: "thumbnail_url", max: 0 }),
    new TextField({ name: "status", max: 0 }),
    new TextField({ name: "template_id", max: 0 }),
    new JSONField({ name: "metadata", maxSize: 0 }),
    new TextField({ name: "rejected_reason", max: 0 }),
    new TextField({ name: "social_account_id", max: 0 }),
    new DateField({ name: "published_at", required: false }),
    new DateField({ name: "scheduled_publish_at", required: false }),
    new FileField({
      name: "media_file",
      maxSelect: 1,
      maxSize: 1073741824,
      mimeTypes: [],
    }),
    new JSONField({ name: "metrics", maxSize: 0 }),
    new VectorField({ name: "embedding", required: false }),
    new TextField({ name: "embedding_model", max: 0 }),
  ])
})
