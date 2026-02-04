import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

(async () => {
  try {
    const [, , fileArg, exportArg] = process.argv;

    if (!fileArg) {
      console.error('Usage: node call.mjs <file> [export]');
      process.exit(2);
      return;
    }

    const modulePath = path.resolve(process.cwd(), fileArg);
    const moduleUrl = pathToFileURL(modulePath).href;

    async function loadParams() {
      if (process.env.PEST_E2E_PARAMS_FILE) {
        const json = await readFile(process.env.PEST_E2E_PARAMS_FILE, 'utf8');
        return JSON.parse(json);
      }

      if (process.env.PEST_E2E_PARAMS) {
        return JSON.parse(process.env.PEST_E2E_PARAMS);
      }

      return {};
    }

    const rawParams = await loadParams();
    const params =
      rawParams && typeof rawParams === 'object' && 'params' in rawParams
        ? rawParams.params
        : rawParams;

    const context = {
      target: process.env.PEST_E2E_TARGET ?? rawParams?.target ?? '',
      runId: process.env.PEST_E2E_RUN_ID ?? rawParams?.runId ?? '',
      params: params ?? {},
      env: process.env,
    };

    const module = await import(moduleUrl);

    let target = null;
    if (exportArg) {
      if (!(exportArg in module)) {
        throw new Error(`Export "${exportArg}" not found in ${modulePath}`);
      }
      target = module[exportArg];
    } else if (typeof module.default === 'function') {
      target = module.default;
    }

    if (typeof target !== 'function') {
      const name = exportArg ?? 'default';
      throw new Error(`Export "${name}" is not a function in ${modulePath}`);
    }

    await target(context);
  } catch (error) {
    const message =
      error instanceof Error && error.stack
        ? error.stack
        : error instanceof Error
          ? error.message
          : String(error);
    console.error(message);
    process.exit(1);
  }
})();
