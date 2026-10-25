Fixed: Backup re-hardens its modes on every run (0700 store directory, 0600 on both new and stale artifacts) and a chmod that fails now raises instead of quietly shipping a world-readable backup
