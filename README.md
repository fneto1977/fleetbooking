# FleetBooking Plugin for GLPI 11

Este plugin permite a gestão de reservas de frotas (veículos) através de um fluxo de aprovação baseado em solicitações e tickets do GLPI.

## Requisitos

- **GLPI**: 11.0.0 ou superior
- **PHP**: 8.1 ou superior (recomendado 8.2+)
- **Banco de Dados**: MySQL 5.7+ ou MariaDB 10.3+

## Características

- **Fluxo de Aprovação**: Usuários solicitam um veículo, o que cria automaticamente um ticket para o gestor do grupo.
- **Validação de Disponibilidade**: Verifica conflitos de data/hora, feriados e horários de funcionamento.
- **Calendário Visual**: Visualização de reservas aprovadas e solicitações pendentes.
- **Configuração por Entidade**: Definição de horários, cores e categorias de ticket por entidade.

## Instalação

1. Clone ou baixe este repositório na pasta `plugins/fleetbooking` do seu GLPI.
2. Vá em **Configurar > Plugins**.
3. Clique em **Instalar** (disquete) e depois em **Ativar** (check).

## Ações Pós-Instalação e Configuração

Após instalar e ativar o plugin, siga rigorosamente os passos abaixo para o funcionamento correto:

1. **Configuração da Entidade:**
   - Vá em **Administração > Entidades**, selecione a entidade desejada e acesse a aba **Fleet Booking Configuration**.
   - Defina a **Categoria ITIL Padrão** que os tickets de reserva usarão, e o **ItemType de Veículos** (ex: computadores, ou um Generic Object). Essa permissão/categoria é essencial para a criação do ticket pela entidade.
   - Configure os horários de funcionamento e cores.

2. **Permissões de Perfil:**
   - Vá em **Administração > Perfis**.
   - É necessário dar permissão de acesso e administração do plugin aos perfis dos usuários que farão a gestão (aprovação) e dos que solicitarão reservas. Verifique a aba correspondente ao plugin dentro da configuração de perfil.

3. **Gerenciamento de Grupos:**
   - Crie ou edite os grupos de usuários do GLPI em **Administração > Grupos**.
   - Defina quem é o **gerente do grupo**. O plugin usará esta informação para encaminhar a solicitação de reserva (ticket) diretamente ao responsável correto pelo grupo do solicitante.
   - Em **Ferramentas > Fleet Booking Request > Fleet Group Managers**, você também pode fazer o mapeamento adicional caso necessário.

4. **Cadastro de Veículos (Ativos):**
   - Na lista de ativos do tipo escolhido no passo 1 (ex: Computadores ou Objetos Genéricos), crie os seus veículos.
   - **Sugestão de nomenclatura:** Utilize o formato `<Nome>-<Placa>` (ex: `Celta-ABC1234`) no nome do ativo para facilitar a identificação visual na hora da reserva.

5. **Feriados (Opcional):**
   - Em **Ferramentas > Fleet Booking Request > Fleet Holidays**, adicione feriados locais para bloquear novas reservas nestes dias.

## Uso

- **Usuários**: Acessam via **Ferramentas > Fleet Booking Request** para solicitar uma reserva.
- **Gestores**: Recebem um ticket. Na aba **Fleet Approval** do ticket, podem aprovar ou rejeitar a solicitação.

## Licença

GPLv3+
