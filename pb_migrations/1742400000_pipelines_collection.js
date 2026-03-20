migrate((app) => {
  try {
    app.findCollectionByNameOrId("pipelines")
    return
  } catch (_) {}

  const collection = new Collection({
    type: "base",
    name: "pipelines",
    listRule: '@request.auth.id != ""',
    viewRule: '@request.auth.id != ""',
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
    ],
  })
  app.save(collection)
})
