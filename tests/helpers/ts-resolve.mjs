/**
 * Resolver hook for the test runner.
 *
 * The source uses extensionless imports ("./db"), which is what Vite and Astro
 * expect. Node's ESM resolver requires an extension, so when a specifier fails
 * to resolve, retry it with ".ts" before giving up.
 */

export async function resolve(specifier, context, nextResolve) {
  try {
    return await nextResolve(specifier, context);
  } catch (error) {
    if (specifier.startsWith(".") && !/\.[a-z]+$/i.test(specifier)) {
      // Try "./x" -> "./x.ts", then the directory form "./x/index.ts".
      for (const candidate of [`${specifier}.ts`, `${specifier}/index.ts`]) {
        try {
          return await nextResolve(candidate, context);
        } catch {
          // Try the next shape.
        }
      }
    }
    throw error;
  }
}
