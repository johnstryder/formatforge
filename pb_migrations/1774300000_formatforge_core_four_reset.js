/// DESTRUCTIVE: drops legacy collections and recreates the core-four schema (VectorBase `vector` fields).
/// Wipes Curate + pipeline rows. Apply after backup; run migrate as `pb_data` owner.
migrate((app) => {
  const drop = (name) => {
    try {
      const c = app.findCollectionByNameOrId(name)
      app.delete(c)
    } catch (_) {}
  }

  ;[
    "content_metrics",
    "pipeline_ingredients",
    "pipeline_formats",
    "content_items",
    "source_links",
    "winning_templates",
    "content_pipelines",
    "instagram_accounts",
    "pipelines",
    "embedding_probe",
  ].forEach(drop)

  const u = {
    listRule: '@request.auth.id != ""',
    viewRule: '@request.auth.id != ""',
    createRule: '@request.auth.id != ""',
    updateRule: '@request.auth.id != ""',
    deleteRule: '@request.auth.id != ""',
  }

  app.save(
    new Collection({
      type: "base",
      name: "pipelines",
      listRule: u.listRule,
      viewRule: u.viewRule,
      createRule: null,
      updateRule: null,
      deleteRule: null,
      fields: [
        { name: "name", type: "text" },
        { name: "description", type: "text" },
        { name: "prompt_template", type: "text" },
        { name: "output_type", type: "text" },
        { name: "is_active", type: "bool" },
        { name: "metadata", type: "json" },
        { name: "cron_schedule", type: "text" },
        { name: "binary_path", type: "text" },
        { name: "rejected_count", type: "number" },
        { name: "approved_count", type: "number" },
        { name: "formats", type: "json" },
      ],
    })
  )

  app.save(
    new Collection({
      type: "base",
      name: "social_accounts",
      listRule: u.listRule,
      viewRule: u.viewRule,
      createRule: u.createRule,
      updateRule: u.updateRule,
      deleteRule: u.deleteRule,
      fields: [
        { name: "platform", type: "text" },
        { name: "instagram_user_id", type: "text" },
        { name: "username", type: "text" },
        { name: "access_token", type: "text" },
        { name: "token_expires_at", type: "date" },
        { name: "is_active", type: "bool" },
        { name: "metadata", type: "json" },
      ],
    })
  )

  app.save(
    new Collection({
      type: "base",
      name: "input_media",
      listRule: u.listRule,
      viewRule: u.viewRule,
      createRule: u.createRule,
      updateRule: u.updateRule,
      deleteRule: u.deleteRule,
      fields: [
        { name: "role", type: "text" },
        { name: "status", type: "text" },
        { name: "url", type: "text" },
        { name: "title", type: "text" },
        { name: "metadata", type: "json" },
        { name: "pipeline_id", type: "text" },
        { name: "slot_index", type: "number" },
        { name: "slot_kind", type: "text" },
        { name: "topic", type: "text" },
        { name: "title_seed", type: "text" },
        { name: "input_url", type: "text" },
        { name: "instruction", type: "text" },
        { name: "is_active", type: "bool" },
        new VectorField({ name: "embedding", required: false }),
        new TextField({ name: "embedding_model", max: 0 }),
      ],
    })
  )

  app.save(
    new Collection({
      type: "base",
      name: "output_media",
      listRule: u.listRule,
      viewRule: u.viewRule,
      createRule: u.createRule,
      updateRule: u.updateRule,
      deleteRule: u.deleteRule,
      fields: [
        { name: "type", type: "text" },
        { name: "title", type: "text" },
        { name: "prompt", type: "text" },
        { name: "input_media_id", type: "text" },
        { name: "garage_key", type: "text" },
        { name: "garage_url", type: "text" },
        { name: "thumbnail_url", type: "text" },
        { name: "status", type: "text" },
        { name: "template_id", type: "text" },
        { name: "metadata", type: "json" },
        { name: "rejected_reason", type: "text" },
        { name: "social_account_id", type: "text" },
        { name: "published_at", type: "date" },
        { name: "scheduled_publish_at", type: "date" },
        { name: "media_file", type: "file", maxSelect: 1, maxSize: 1073741824, mimeTypes: [] },
        { name: "metrics", type: "json" },
        new VectorField({ name: "embedding", required: false }),
        new TextField({ name: "embedding_model", max: 0 }),
      ],
    })
  )
})
