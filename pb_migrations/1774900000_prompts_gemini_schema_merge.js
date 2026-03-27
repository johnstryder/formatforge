/// Merge Gemini-oriented prompts fields for DBs that already ran the older 1774800000_prompts_collection.js
/// (input_media / output_media / embedding / embedding_model). Safe to run on fresh DBs (no-op if complete).
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

  const fieldNames = (col) => new Set((col.fields || []).map((f) => f && f.name).filter(Boolean))

  if (!promptsCol) {
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
    return
  }

  const have = fieldNames(promptsCol)
  const toAdd = []
  if (!have.has("prompt_text")) {
    toAdd.push(new TextField({ name: "prompt_text", max: 0 }))
  }
  if (!have.has("prompt_embedding")) {
    toAdd.push(new VectorField({ name: "prompt_embedding", required: false }))
  }
  if (!have.has("prompt_original_media")) {
    toAdd.push(
      new RelationField({
        name: "prompt_original_media",
        collectionId: inputCol.id,
        cascadeDelete: false,
        maxSelect: 1,
        minSelect: 0,
        required: false,
      })
    )
  }
  if (!have.has("prompt_generated_media")) {
    toAdd.push(
      new RelationField({
        name: "prompt_generated_media",
        collectionId: outputCol.id,
        cascadeDelete: false,
        maxSelect: 200,
        minSelect: 0,
        required: false,
      })
    )
  }

  promptsCol.listRule = auth
  promptsCol.viewRule = auth
  promptsCol.createRule = auth
  promptsCol.updateRule = auth
  promptsCol.deleteRule = auth

  if (toAdd.length > 0) {
    promptsCol.fields = [...(promptsCol.fields || []), ...toAdd]
  }
  app.save(promptsCol)

  promptsCol = app.findCollectionByNameOrId("prompts")
  const have2 = fieldNames(promptsCol)
  const toAdd2 = []
  if (!have2.has("prompt_parent")) {
    toAdd2.push(
      new RelationField({
        name: "prompt_parent",
        collectionId: promptsCol.id,
        cascadeDelete: false,
        maxSelect: 1,
        minSelect: 0,
        required: false,
      })
    )
  }
  if (!have2.has("prompt_children")) {
    toAdd2.push(
      new RelationField({
        name: "prompt_children",
        collectionId: promptsCol.id,
        cascadeDelete: false,
        maxSelect: 200,
        minSelect: 0,
        required: false,
      })
    )
  }
  if (toAdd2.length > 0) {
    promptsCol.fields = [...(promptsCol.fields || []), ...toAdd2]
    app.save(promptsCol)
  }
})
