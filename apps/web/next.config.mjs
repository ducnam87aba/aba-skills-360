/** @type {import('next').NextConfig} */
// When mocks are disabled, the browser calls same-origin /wp-json/aba/v1 and the
// Next dev server proxies it to the WordPress backend (cookie session stays
// same-origin, avoiding CORS). Set WORDPRESS_ORIGIN to override the target.
const wordpressOrigin = process.env.WORDPRESS_ORIGIN || 'http://localhost:8080';

const nextConfig = {
  transpilePackages: ['@aba/ui', '@aba/api-client', '@aba/types', '@aba/validation'],
  reactStrictMode: true,
  async rewrites() {
    if (process.env.NEXT_PUBLIC_ENABLE_MOCKS === 'false') {
      return [
        {
          source: '/wp-json/:path*',
          destination: `${wordpressOrigin}/wp-json/:path*`,
        },
      ];
    }
    return [];
  },
};

export default nextConfig;
