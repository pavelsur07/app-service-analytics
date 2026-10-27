/** Header identity/version can stay unchanged after a failed comparison read. */
export async function recoverPerformance(
  readHeader: () => Promise<unknown>,
  readComparison: () => Promise<unknown>,
): Promise<void> {
  await readHeader()
  await readComparison()
}
