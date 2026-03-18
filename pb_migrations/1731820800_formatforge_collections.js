migrate((db) => {
  const dao = new Dao(db)
  const createIfMissing = (config) => {
    try {
      dao.findCollectionByNameOrId(config.name)
      return
    } catch (_) {}
    const collection = new Collection(config)
    dao.saveCollection(collection)
  }

  // users collection is created by PocketBase by default

  createIfMissing({
    type: "base",
    name: "source_links",
    listRule: "@request.auth.id != \"\"",
    viewRule: "@request.auth.id != \"\"",
    createRule: "@request.auth.id != \"\"",
    updateRule: "@request.auth.id != \"\"",
    deleteRule: "@request.auth.id != \"\"",
    fields: [
      { name: "url", type: "text" },
      { name: "title", type: "text" },
      { name: "status", type: "text" },
      { name: "metadata", type: "json" },
    ],
  })

  createIfMissing({
    type: "base",
    name: "instagram_accounts",
    listRule: "@request.auth.id != \"\"",
    viewRule: "@request.auth.id != \"\"",
    createRule: "@request.auth.id != \"\"",
    updateRule: "@request.auth.id != \"\"",
    deleteRule: "@request.auth.id != \"\"",
    fields: [
      { name: "instagram_user_id", type: "text" },
      { name: "username", type: "text" },
      { name: "access_token", type: "text" },
      { name: "token_expires_at", type: "date" },
      { name: "is_active", type: "bool" },
    ],
  })

  createIfMissing({
    type: "base",
    name: "content_items",
    listRule: "@request.auth.id != \"\"",
    viewRule: "@request.auth.id != \"\"",
    createRule: "@request.auth.id != \"\"",
    updateRule: "@request.auth.id != \"\"",
    deleteRule: "@request.auth.id != \"\"",
    fields: [
      { name: "type", type: "text" },
      { name: "title", type: "text" },
      { name: "prompt", type: "text" },
      { name: "source_link_id", type: "text" },
      { name: "garage_key", type: "text" },
      { name: "garage_url", type: "text" },
      { name: "thumbnail_url", type: "text" },
      { name: "status", type: "text" },
      { name: "template_id", type: "text" },
      { name: "metadata", type: "json" },
      { name: "rejected_reason", type: "text" },
      { name: "instagram_account_id", type: "text" },
      { name: "published_at", type: "date" },
    ],
  })

  createIfMissing({
    type: "base",
    name: "winning_templates",
    listRule: "@request.auth.id != \"\"",
    viewRule: "@request.auth.id != \"\"",
    createRule: "@request.auth.id != \"\"",
    updateRule: "@request.auth.id != \"\"",
    deleteRule: "@request.auth.id != \"\"",
    fields: [
      { name: "name", type: "text" },
      { name: "hook_style", type: "text" },
      { name: "visual_layout", type: "text" },
      { name: "pacing_config", type: "json" },
      { name: "view_share_ratio", type: "number" },
      { name: "total_views", type: "number" },
      { name: "total_shares", type: "number" },
      { name: "weight", type: "number" },
      { name: "is_active", type: "bool" },
    ],
  })

  createIfMissing({
    type: "base",
    name: "content_metrics",
    listRule: "@request.auth.id != \"\"",
    viewRule: "@request.auth.id != \"\"",
    createRule: "@request.auth.id != \"\"",
    updateRule: "@request.auth.id != \"\"",
    deleteRule: "@request.auth.id != \"\"",
    fields: [
      { name: "content_item_id", type: "text" },
      { name: "instagram_media_id", type: "text" },
      { name: "impressions", type: "number" },
      { name: "likes", type: "number" },
      { name: "views", type: "number" },
      { name: "shares", type: "number" },
      { name: "comments", type: "number" },
      { name: "fetched_at", type: "date" },
    ],
  })
})
