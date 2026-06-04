#!/usr/bin/env node

import { execFileSync, spawnSync } from "node:child_process";

const rawFileList = execFileSync("git", ["ls-files", "*.php"], {
  encoding: "utf8",
}).trim();

const files = rawFileList === "" ? [] : rawFileList.split("\n");

for (const file of files) {
  const result = spawnSync("php", ["-l", file], { stdio: "inherit" });
  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}
