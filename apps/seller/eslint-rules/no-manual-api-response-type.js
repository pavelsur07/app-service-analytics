import { ESLintUtils } from '@typescript-eslint/utils'
import ts from 'typescript'

// По существу, не по имени (CLAUDE.md §10): смотрит не на то, как назван
// тип, а на то, где он объявлен. Точки входа API-ответов в приложение —
// apiGet<T>(), apiPost<T>() и createCompanyApiClient(companyId).get<T>()
// (тот же apiGet внутри, привязанный к companyId, CLAUDE.md §7); fetch
// запрещён везде вне src/api/, see no-restricted-globals. Если T —
// интерфейс или type с телом-литералом, объявленный руками (не реэкспорт/
// индексированный доступ из схемы), это ручное описание ответа, независимо
// от имени: AppInfo, PingResult, AppInfoDto — ловится одинаково.
export default ESLintUtils.RuleCreator.withoutDocs({
  meta: {
    type: 'problem',
    docs: {
      description:
        'apiGet<T>() / apiPost<T>() / createCompanyApiClient(...).get<T>() type argument must originate from the generated OpenAPI schema, not a hand-authored shape.',
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

    // Прямой apiGet<T>(...)/apiPost<T>(...) или client.get<T>(...), где
    // client создан прямо на месте через createCompanyApiClient(...)
    // (docs/patterns.md — цепочкой, не через промежуточную переменную;
    // так же вызывается и в текущем потребителе, useSalesFacts).
    function isTrackedCall(node) {
      if (node.callee.type === 'Identifier') {
        return node.callee.name === 'apiGet' || node.callee.name === 'apiPost'
      }

      return (
        node.callee.type === 'MemberExpression' &&
        node.callee.property.type === 'Identifier' &&
        node.callee.property.name === 'get' &&
        node.callee.object.type === 'CallExpression' &&
        node.callee.object.callee.type === 'Identifier' &&
        node.callee.object.callee.name === 'createCompanyApiClient'
      )
    }

    return {
      CallExpression(node) {
        if (!isTrackedCall(node)) {
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
