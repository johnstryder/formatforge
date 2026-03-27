/// prompts: prompt text + Gemini embedding + relations (input_media, output_media, parent/children).
/// Two-step create so self-relations reference the saved collection id. Idempotent for fresh installs.
migrate((app) => {
  const auth = '@request.auth.id != ""'

  let inputCol
  let outputCol
  try {
    inputCol = app.findCollectionByNameOrId("input_media")
    outputCol = app.findCollectionByNameOrId("output_media")
  } catch (_) {
    return
  }

  let promptsCol = null
  try {
    promptsCol = app.findCollectionByNameOrId("prompts")
  } catch (_) {
    promptsCol = null
  }

  if (promptsCol) {
    return
  }

  app.save(
    new Collection({
      type: "base",
      name: "prompts",
      listRule: auth,
      viewRule: auth,
      createRule: auth,
      updateRule: auth,
      deleteRule: auth,
      fields: [
        new TextField({ name: "prompt_text", max: 0 }),
        new VectorField({ name: "prompt_embedding", required: false }),
        new RelationField({
          name: "prompt_original_media",
          collectionId: inputCol.id,
          cascadeDelete: false,
          maxSelect: 1,
          minSelect: 0,
          required: false,
        }),
        new RelationField({
          name: "prompt_generated_media",
          collectionId: outputCol.id,
          cascadeDelete: false,
          maxSelect: 200,
          minSelect: 0,
          required: false,
        }),
      ],
    })
  )

  promptsCol = app.findCollectionByNameOrId("prompts")
  promptsCol.fields = [
    ...(promptsCol.fields || []),
    new RelationField({
      name: "prompt_parent",
      collectionId: promptsCol.id,
      cascadeDelete: false,
      maxSelect: 1,
      minSelect: 0,
      required: false,
    }),
    new RelationField({
      name: "prompt_children",
      collectionId: promptsCol.id,
      cascadeDelete: false,
      maxSelect: 200,
      minSelect: 0,
      required: false,
    }),
  ]
  app.save(promptsCol)
})
