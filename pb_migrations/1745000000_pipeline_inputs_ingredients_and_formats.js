migrate((app) => {
  const createIfMissing = (config) => {
    try {
      app.findCollectionByNameOrId(config.name)
      return
    } catch (_) {}
    const c = new Collection(config)
    app.save(c)
  }

  createIfMissing({
    type: "base",
    name: "pipeline_formats",
    listRule: '@request.auth.id != ""',
    viewRule: '@request.auth.id != ""',
    createRule: '@request.auth.id != ""',
    updateRule: '@request.auth.id != ""',
    deleteRule: '@request.auth.id != ""',
    fields: [
      { name: "pipeline_id", type: "text" },
      { name: "name", type: "text" },
      { name: "slot_signature", type: "text" }, // example: video,image,video
      { name: "is_default", type: "bool" },
      { name: "is_active", type: "bool" },
      { name: "metadata", type: "json" },
    ],
  })

  createIfMissing({
    type: "base",
    name: "pipeline_ingredients",
    listRule: '@request.auth.id != ""',
    viewRule: '@request.auth.id != ""',
    createRule: '@request.auth.id != ""',
    updateRule: '@request.auth.id != ""',
    deleteRule: '@request.auth.id != ""',
    fields: [
      { name: "pipeline_id", type: "text" },
      { name: "slot_index", type: "number" },
      { name: "slot_kind", type: "text" }, // image|video
      { name: "topic", type: "text" },
      { name: "title_seed", type: "text" },
      { name: "input_url", type: "text" }, // editorial backing ref
      { name: "instruction", type: "text" }, // renderer-agnostic slot instruction
      { name: "is_active", type: "bool" },
      { name: "metadata", type: "json" },
    ],
  })
})
