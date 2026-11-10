import { thumbnailUrl } from '../lib/photo'

export function ProductThumbnail({ url }: { url: string | null | undefined }) {
  const src = thumbnailUrl(url)
  if (src === null) {
    return <span className="block size-8 shrink-0 rounded bg-border-subtle" />
  }
  return (
    <img
      alt=""
      className="size-8 shrink-0 rounded bg-border-subtle object-cover"
      decoding="async"
      height={32}
      loading="lazy"
      src={src}
      width={32}
    />
  )
}
