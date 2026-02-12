import { readFile } from 'fs/promises';

export const DEFAULT_AUTH_ENDPOINT = '/pest-e2e/auth/login';

/**
 * Read the raw/root params payload from env (inline JSON) or file.
 */
export async function readParamsRoot() {
  const inlineParams = process.env.PEST_E2E_PARAMS;

  if (inlineParams) {
    try {
      return JSON.parse(inlineParams);
    } catch (error) {
      throw new Error(
        `Failed to parse PEST_E2E_PARAMS as JSON: ${error instanceof Error ? error.message : String(error)}`
      );
    }
  }

  const paramsFile = process.env.PEST_E2E_PARAMS_FILE;

  if (paramsFile) {
    try {
      return JSON.parse((await readFile(paramsFile, 'utf8')).replace(/^\uFEFF/, ''));
    } catch (error) {
      throw new Error(
        `Failed to read/parse params file '${paramsFile}': ${error instanceof Error ? error.message : String(error)}`
      );
    }
  }

  return {};
}

/**
 * Read params and return the inner `params` object if present, otherwise the root object.
 */
export async function readParams() {
  const root = await readParamsRoot();
  return (root.params ?? root);
}

/**
 * Get the app URL from the environment or the params.
 */
export function getAppUrl(params) {
  const appUrl = process.env.APP_URL;

  if (appUrl) return appUrl.replace(/\/$/, '');
  if (params?.baseUrl) return params.baseUrl.replace(/\/$/, '');

  throw new Error('APP_URL environment variable or params.baseUrl is required');
}

/**
 * Get the auth endpoint from the app URL.
 */
export function getAuthEndpoint(params) {
  const appUrl = getAppUrl(params);
  return `${appUrl}${DEFAULT_AUTH_ENDPOINT}`;
}

/**
 * Check if the auth ticket is present in the params.
 */
export function hasAuthTicket(params) {
  return Boolean(params.auth?.ticket);
}

/**
 * Get the auth ticket from the params.
 */
export function getAuthTicket(params) {
  if (!hasAuthTicket(params)) {
    throw new Error('Authentication ticket not found in params.auth.ticket');
  }

  return params.auth.ticket;
}
