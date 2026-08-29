import { ESLintUtils } from '@typescript-eslint/utils'
import ts from 'typescript'

// По существу, не по имени (CLAUDE.md §10): смотрит не на то, как назван
// тип, а на то, где он объявлен. Точки входа API-ответов в приложение —
// apiGet<T>() и apiPost<T>() (fetch запрещён везде вне src/api/, see
// no-restricted-globals). Если T — интерфейс или type с телом-литералом,
// объявленный руками (не реэкспорт/индексированный доступ из схемы), это
// ручное описание ответа, независимо от имени: AppInfo, PingResult,
// AppInfoDto — ловится одинаково.
//
// createCompanyApiClient из apps/seller здесь не разбирается: системный
// контур не скоупится на компанию (ADR-017), такого клиента в этом
// приложении нет.
export default ESLintUtils.RuleCreator.withoutDocs({
  meta: {
    type: 'problem',
    docs: {
      description:
        'apiGet<T>() / apiPost<T>() type argument must originate from the generated OpenAPI schema, not a hand-authored shape.',
    },
    messages: {
      manual:
        'Типы ответов API — импорт из сгенерированной схемы (packages/api-schema), ручное описание запрещено (CLAUDE.md §10).',
    },
    schema: [],
  },
  defaultOptions: [],
  create(context) {
    const services = ESLintUtils.getParserServices(context)
    const checker = services.program.getTypeChecker()

    return {
      CallExpression(node) {
        if (
          node.callee.type !== 'Identifier' ||
          (node.callee.name !== 'apiGet' && node.callee.name !== 'apiPost')
        ) {
          return
        }

        const typeArgs = node.typeArguments
        if (!typeArgs || typeArgs.params.length === 0) {
          return
        }

        const typeNode = typeArgs.params[0]
        if (
          typeNode.type !== 'TSTypeReference' ||
          typeNode.typeName.type !== 'Identifier'
        ) {
          return
        }

        const tsIdentifier = services.esTreeNodeToTSNodeMap.get(
          typeNode.typeName,
        )
        const symbol = checker.getSymbolAtLocation(tsIdentifier)
        const declarations = symbol?.getDeclarations() ?? []

        const handAuthored = declarations.some(
          (decl) =>
            ts.isInterfaceDeclaration(decl) ||
            (ts.isTypeAliasDeclaration(decl) &&
              ts.isTypeLiteralNode(decl.type)),
        )

        if (handAuthored) {
          context.report({ node: typeNode, messageId: 'manual' })
        }
      },
    }
  },
})
