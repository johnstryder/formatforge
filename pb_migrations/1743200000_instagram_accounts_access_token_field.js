/// Ensure instagram_accounts.access_token can store long Meta page tokens (and is API-writable).
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("instagram_accounts")
  } catch (_) {
    return
  }
  if (!col || !Array.isArray(col.fields) || col.fields.length === 0) {
    return
  }

  let changed = false
  for (const f of col.fields) {
    if (!f || f.name !== "access_token") continue
    if (f.hidden === true) {
      f.hidden = false
      changed = true
    }
    if (f.type !== "text") continue
    const max = typeof f.max === "number" ? f.max : null
    if (max !== null && max > 0 && max < 20000) {
      f.max = 0
      changed = true
    }
  }
  if (changed) {
    app.save(col)
  }
})
