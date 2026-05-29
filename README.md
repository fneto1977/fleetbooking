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

## Configuração

1. Vá em **Administração > Entidades**.
2. Selecione a entidade desejada e clique na aba **Fleet Booking Configuration**.
3. Defina a **Categoria ITIL Padrão** e o **ItemType de Veículos** (ex: computadores, periféricos ou um Generic Object de frotas).
4. Configure os horários de funcionamento e cores.
5. Em **Ferramentas > Fleet Booking Request > Fleet Group Managers**, mapeie seus grupos aos respectivos gestores.
6. Em **Ferramentas > Fleet Booking Request > Fleet Holidays**, adicione os feriados para bloquear reservas nesses dias.

## Uso

- **Usuários**: Acessam via **Ferramentas > Fleet Booking Request** para solicitar uma reserva.
- **Gestores**: Recebem um ticket. Na aba **Fleet Approval** do ticket, podem aprovar ou rejeitar a solicitação.

## Licença

GPLv3+
